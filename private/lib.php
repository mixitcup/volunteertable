<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/schema.php';

const VT_SESSION_COOKIE = 'vt_session';
// Browsers cap cookie lifetimes at 400 days; the cookie is renewed on every visit.
const VT_COOKIE_LIFETIME = 400 * 24 * 60 * 60;
// Minimum time between two re-sent edit links for the same booking.
const VT_RESEND_INTERVAL = 5 * 60;
const VT_NAME_MAX_LENGTH = 80;

function vt_config(): array
{
    static $config = null;
    if ($config === null) {
        $defaults = [
            'db' => [
                'dsn' => 'sqlite:' . __DIR__ . '/data/volunteertable.sqlite',
                'username' => null,
                'password' => null,
            ],
            'base_url' => '',
            'mail_from' => '',
            'mail_from_name' => 'Volunteer table',
            'smtp' => null,
        ];
        $file = __DIR__ . '/config.php';
        $custom = is_file($file) ? require $file : [];
        $config = array_merge($defaults, is_array($custom) ? $custom : []);
    }
    return $config;
}

/* ---------------------------------------------------------------------------
 * Database
 * ------------------------------------------------------------------------- */

function vt_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $settings = vt_config()['db'];
    $dsn = (string) $settings['dsn'];
    if (strpos($dsn, 'sqlite:') === 0) {
        $path = substr($dsn, strlen('sqlite:'));
        $dir = dirname($path);
        if ($path !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create database directory ' . $dir);
        }
    }

    $db = new PDO($dsn, $settings['username'] ?? null, $settings['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    if (vt_db_driver($db) === 'sqlite') {
        $db->exec('PRAGMA foreign_keys = ON');
    }

    vt_ensure_schema($db);
    return $db;
}

function vt_db_driver(PDO $db): string
{
    return (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * Runs $fn inside a transaction that blocks concurrent writers, so checks
 * like "is there still a free place?" stay true until the booking is saved.
 */
function vt_transaction(callable $fn)
{
    $db = vt_db();
    $sqlite = vt_db_driver($db) === 'sqlite';
    if ($sqlite) {
        // A plain BEGIN would only lock the database at the first write.
        $db->exec('BEGIN IMMEDIATE');
    } else {
        $db->beginTransaction();
    }

    try {
        $result = $fn($db);
        $sqlite ? $db->exec('COMMIT') : $db->commit();
        return $result;
    } catch (Throwable $e) {
        $sqlite ? $db->exec('ROLLBACK') : $db->rollBack();
        throw $e;
    }
}

/** Row lock suffix for SELECTs in a transaction (SQLite locks the whole database instead). */
function vt_for_update(): string
{
    return vt_db_driver(vt_db()) === 'sqlite' ? '' : ' FOR UPDATE';
}

function vt_query(string $sql, array $params = []): PDOStatement
{
    $statement = vt_db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function vt_new_id(): string
{
    return bin2hex(random_bytes(16));
}

/** Inserts a row unless an identical one exists (portable INSERT IGNORE for link tables). */
function vt_insert_link(string $table, array $row): void
{
    $columns = array_keys($row);
    $where = implode(' AND ', array_map(function ($column) { return "$column = ?"; }, $columns));
    $exists = vt_query("SELECT 1 FROM $table WHERE $where", array_values($row))->fetchColumn();
    if ($exists !== false) {
        return;
    }
    try {
        vt_query(
            "INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($row)
        );
    } catch (PDOException $e) {
        // Inserted concurrently by another request: that's fine.
        if (vt_query("SELECT 1 FROM $table WHERE $where", array_values($row))->fetchColumn() === false) {
            throw $e;
        }
    }
}

/* ---------------------------------------------------------------------------
 * Tokens
 * ------------------------------------------------------------------------- */

function vt_new_token(): string
{
    return bin2hex(random_bytes(32));
}

/** Only hashes of tokens are stored, so a leaked database contains no working links. */
function vt_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function vt_is_token_format($token): bool
{
    return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1;
}

/* ---------------------------------------------------------------------------
 * Input validation
 * ------------------------------------------------------------------------- */

function vt_normalize_name($name): ?string
{
    if (!is_string($name)) {
        return null;
    }
    // Collapses whitespace; also fails (returns null) on invalid UTF-8.
    $name = preg_replace('/\s+/u', ' ', trim($name));
    if ($name === null || $name === '' || preg_match('/\p{C}/u', $name) === 1) {
        return null;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    return $length <= VT_NAME_MAX_LENGTH ? $name : null;
}

function vt_normalize_email($email): ?string
{
    if (!is_string($email)) {
        return null;
    }
    $email = trim($email);
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

/** Key used to compare email addresses (case-insensitive). */
function vt_email_key(string $email): string
{
    return strtolower($email);
}

/* ---------------------------------------------------------------------------
 * Browser sessions: a long-lived cookie remembering which bookings and email
 * addresses belong to this browser ("My events").
 * ------------------------------------------------------------------------- */

function vt_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** URL path of the site folder (the one containing index.htm), with trailing slash. */
function vt_site_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/app.php');
    return rtrim(str_replace('\\', '/', dirname(dirname($script))), '/') . '/';
}

function vt_set_session_cookie(string $token): void
{
    setcookie(VT_SESSION_COOKIE, $token, [
        'expires' => time() + VT_COOKIE_LIFETIME,
        'path' => vt_site_path(),
        'secure' => vt_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Hash of this browser's session, or null if it has none. With $create, a
 * new session is started when needed. Known sessions get their cookie renewed.
 */
function vt_session(bool $create): ?string
{
    static $current = null;
    if ($current !== null) {
        return $current;
    }

    $token = $_COOKIE[VT_SESSION_COOKIE] ?? null;
    if (vt_is_token_format($token)) {
        $hash = vt_hash_token($token);
        $updated = vt_query('UPDATE vt_sessions SET last_seen_at = ? WHERE id_hash = ?', [time(), $hash])->rowCount();
        $known = $updated > 0 || vt_query('SELECT 1 FROM vt_sessions WHERE id_hash = ?', [$hash])->fetchColumn() !== false;
        if ($known) {
            vt_set_session_cookie($token);
            return $current = $hash;
        }
    }

    if (!$create) {
        return null;
    }
    $token = vt_new_token();
    $hash = vt_hash_token($token);
    vt_query('INSERT INTO vt_sessions (id_hash, created_at, last_seen_at) VALUES (?, ?, ?)', [$hash, time(), time()]);
    vt_set_session_cookie($token);
    return $current = $hash;
}

/** All bookings that belong to this browser, either directly or through a linked email address. */
function vt_session_bookings(?string $session): array
{
    if ($session === null) {
        return [];
    }
    return vt_query(
        'SELECT b.id, b.event_id, b.name, b.email, b.created_at FROM vt_bookings b
         WHERE b.id IN (SELECT booking_id FROM vt_session_bookings WHERE session_hash = ?)
            OR b.email_key IN (SELECT email_key FROM vt_session_emails WHERE session_hash = ?)
         ORDER BY b.created_at',
        [$session, $session]
    )->fetchAll();
}

function vt_session_owns_booking(?string $session, string $bookingId): bool
{
    foreach (vt_session_bookings($session) as $booking) {
        if ($booking['id'] === $bookingId) {
            return true;
        }
    }
    return false;
}

function vt_delete_booking(string $bookingId): void
{
    vt_transaction(function () use ($bookingId) {
        vt_query('DELETE FROM vt_session_bookings WHERE booking_id = ?', [$bookingId]);
        vt_query('DELETE FROM vt_bookings WHERE id = ?', [$bookingId]);
    });
}

/* ---------------------------------------------------------------------------
 * Admins
 * ------------------------------------------------------------------------- */

function vt_admin_exists(): bool
{
    return vt_query('SELECT 1 FROM vt_admins')->fetchColumn() !== false;
}

/** The admin logged in in this browser, or null. */
function vt_session_admin(?string $session): ?array
{
    if ($session === null) {
        return null;
    }
    $admin = vt_query(
        'SELECT a.id, a.name, a.email FROM vt_session_admins sa JOIN vt_admins a ON a.id = sa.admin_id
         WHERE sa.session_hash = ?',
        [$session]
    )->fetch();
    return $admin === false ? null : $admin;
}

/* ---------------------------------------------------------------------------
 * Page texts the admin can edit
 * ------------------------------------------------------------------------- */

const VT_PAGE_TEXT_DEFAULTS = [
    'page_title' => 'VolunteerTable',
    'page_intro' => "Thank you for volunteering! This event only works because of people like you.\n"
        . 'Have a look at the timetable and pick the shifts you would like to help with.',
];

/** Title and intro text of the page, falling back to the defaults. */
function vt_page_texts(): array
{
    $texts = VT_PAGE_TEXT_DEFAULTS;
    foreach (vt_query('SELECT name, value FROM vt_texts')->fetchAll() as $row) {
        if (array_key_exists($row['name'], $texts)) {
            $texts[$row['name']] = $row['value'];
        }
    }
    return ['title' => $texts['page_title'], 'intro' => $texts['page_intro']];
}

function vt_save_text(string $name, string $value): void
{
    vt_transaction(function () use ($name, $value) {
        vt_query('DELETE FROM vt_texts WHERE name = ?', [$name]);
        vt_query('INSERT INTO vt_texts (name, value) VALUES (?, ?)', [$name, $value]);
    });
}

/* ---------------------------------------------------------------------------
 * Access: only invited browsers may see and use the page.
 * ------------------------------------------------------------------------- */

/**
 * The shared invitation token that all volunteers get. Unlike personal links
 * it is stored in plain text, so the admin can always look it up and share it.
 */
function vt_invite_token(): string
{
    $token = vt_query("SELECT value FROM vt_settings WHERE name = 'invite_token'")->fetchColumn();
    if ($token !== false) {
        return $token;
    }
    try {
        vt_query("INSERT INTO vt_settings (name, value) VALUES ('invite_token', ?)", [vt_new_token()]);
    } catch (PDOException $e) {
        // Created at the same moment by another request; use that one.
    }
    return (string) vt_query("SELECT value FROM vt_settings WHERE name = 'invite_token'")->fetchColumn();
}

function vt_invite_link(): ?string
{
    return vt_site_link('?invite=' . vt_invite_token(), false);
}

function vt_has_access(?string $session): bool
{
    if ($session === null) {
        return false;
    }
    return vt_query('SELECT 1 FROM vt_session_access WHERE session_hash = ?', [$session])->fetchColumn() !== false
        || vt_session_admin($session) !== null;
}

/** Gives this browser access (starting a session if needed). */
function vt_grant_access(): string
{
    $session = vt_session(true);
    vt_insert_link('vt_session_access', ['session_hash' => $session]);
    return $session;
}

/** Logs the admin in in this browser. */
function vt_login_admin(string $adminId): void
{
    $session = vt_session(true);
    vt_query('DELETE FROM vt_session_admins WHERE session_hash = ?', [$session]);
    vt_query('INSERT INTO vt_session_admins (session_hash, admin_id) VALUES (?, ?)', [$session, $adminId]);
}

/* ---------------------------------------------------------------------------
 * Links and mail
 * ------------------------------------------------------------------------- */

/**
 * Link to the page with the given query and hash. Links in emails must use
 * the configured base_url: a URL derived from the request's Host header could
 * be forged to send someone's token to another server.
 */
function vt_site_link(string $queryAndHash, bool $forEmail): ?string
{
    $base = rtrim((string) vt_config()['base_url'], '/');
    if ($base === '') {
        if ($forEmail && vt_config()['smtp'] !== null) {
            return null;
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = (vt_is_https() ? 'https' : 'http') . '://' . $host . rtrim(vt_site_path(), '/');
    }
    return $base . '/index.htm' . $queryAndHash;
}

/** Edit link for a booking token. */
function vt_edit_link(string $token, bool $forEmail): ?string
{
    return vt_site_link('?booking=' . $token . '#my-events-tab', $forEmail);
}

/** Login link for an admin token. */
function vt_admin_link(string $token, bool $forEmail): ?string
{
    return vt_site_link('?admin=' . $token . '#admin-tab', $forEmail);
}

/** Sends a plain-text mail (or logs it if no SMTP server is configured). Throws on failure. */
function vt_send_mail(string $toEmail, string $toName, string $subject, string $body): void
{
    $config = vt_config();
    if ($config['smtp'] === null) {
        // No SMTP configured: log instead of sending, for local testing.
        error_log("[volunteertable] Mail to {$toEmail}: {$subject}\n{$body}");
        return;
    }
    if (vt_normalize_email($config['mail_from']) === null) {
        throw new RuntimeException('mail_from must be configured to send mails');
    }

    vt_smtp_send(
        $config['smtp'],
        $config['mail_from'],
        (string) $config['mail_from_name'],
        $toEmail,
        $toName,
        $subject,
        $body
    );
}

/** Sends the admin their login link. Throws on failure. */
function vt_send_admin_mail(array $admin, string $token): void
{
    $link = vt_admin_link($token, true);
    if ($link === null) {
        throw new RuntimeException('base_url must be configured to send mails');
    }

    vt_send_mail($admin['email'], $admin['name'], 'Your admin link for the volunteer table',
        "Hello {$admin['name']},\n\n"
        . "you are now the admin of the volunteer table. To log in as admin, also on\n"
        . "another device or browser, open this link:\n\n"
        . "{$link}\n\n"
        . "Anyone with this link can manage all registrations, so please keep it safe.\n");
}

function vt_format_event_time(array $event): string
{
    $start = DateTime::createFromFormat('Y-m-d\TH:i:s', $event['starts_at']);
    $end = DateTime::createFromFormat('Y-m-d\TH:i:s', $event['ends_at']);
    if ($start === false || $end === false) {
        return $event['starts_at'] . ' – ' . $event['ends_at'];
    }
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    return $start->format('D j M Y, H:i') . ' – ' . $end->format($sameDay ? 'H:i' : 'D j M Y, H:i');
}

/** Sends the confirmation with the edit link. Throws on failure. */
function vt_send_booking_mail(array $booking, array $event, string $mailToken): void
{
    $link = vt_edit_link($mailToken, true);
    if ($link === null) {
        throw new RuntimeException('base_url must be configured to send mails');
    }

    $subject = 'Your registration: ' . $event['name'];
    $body = "Hello {$booking['name']},\n\n"
        . "you are registered for:\n\n"
        . "  {$event['name']}\n"
        . '  ' . vt_format_event_time($event) . "\n"
        . "  {$event['location_name']}\n\n"
        . "To change or cancel your registration, open this link:\n\n"
        . "{$link}\n\n"
        . "Opening the link also shows all your registrations made with this email\n"
        . "address under \"My events\" in that browser.\n"
        . "Anyone with this link can change your registration, so please don't share it.\n";

    vt_send_mail($booking['email'], $booking['name'], $subject, $body);
}
