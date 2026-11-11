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
        $custom = is_array($custom) ? $custom : [];
        $config = array_merge($defaults, $custom);
        $config['db'] = vt_db_settings($custom, $defaults['db']);
    }
    return $config;
}

/**
 * Database connection, in this order of precedence:
 *  1. 'db' => ['dsn' => ..., 'username' => ..., 'password' => ...] in config.php (any PDO database);
 *  2. the Engelsystem format: 'database' => ['driver', 'host', 'database', 'username', 'password']
 *     in config.php, with the Engelsystem's environment variables MYSQL_TYPE, MYSQL_HOST,
 *     MYSQL_DATABASE, MYSQL_USER and MYSQL_PASSWORD as defaults (config.php wins);
 *  3. otherwise a SQLite file in private/data/ for testing.
 * Both 1. and 2. also accept 'options' (PDO options, e.g. PDO::MYSQL_ATTR_SSL_CA)
 * and 'sslmode' (see vt_check_sslmode()).
 */
function vt_db_settings(array $custom, array $sqliteDefault): array
{
    if (isset($custom['db']) && is_array($custom['db'])) {
        return array_merge($sqliteDefault, $custom['db']);
    }

    $fromEnv = [];
    foreach (['driver' => 'MYSQL_TYPE', 'host' => 'MYSQL_HOST', 'database' => 'MYSQL_DATABASE',
              'username' => 'MYSQL_USER', 'password' => 'MYSQL_PASSWORD'] as $key => $variable) {
        $value = getenv($variable);
        if ($value !== false) {
            $fromEnv[$key] = $value;
        }
    }
    $fromFile = isset($custom['database']) && is_array($custom['database']) ? $custom['database'] : [];
    if ($fromEnv === [] && $fromFile === []) {
        return $sqliteDefault;
    }

    // Same defaults as the Engelsystem.
    $database = array_merge(
        ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'engelsystem', 'username' => 'root', 'password' => ''],
        $fromEnv,
        $fromFile
    );
    if (!in_array($database['driver'], ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('Unsupported database driver "' . $database['driver'] . '" (use mysql or mariadb)');
    }
    // MariaDB uses PHP's MySQL driver.
    return [
        'dsn' => 'mysql:host=' . $database['host'] . ';dbname=' . $database['database'] . ';charset=utf8mb4',
        'username' => (string) $database['username'],
        'password' => (string) $database['password'],
        'options' => $database['options'] ?? [],
        'sslmode' => $database['sslmode'] ?? null,
    ];
}

/**
 * For MySQL/MariaDB, 'sslmode' => 'require' makes sure the connection is encrypted
 * (e.g. together with PDO::MYSQL_ATTR_SSL_CA in 'options'); otherwise nothing is
 * done with the database. 'prefer' and 'disable' don't enforce anything.
 */
function vt_check_sslmode(PDO $db, ?string $sslmode): void
{
    if ($sslmode === null || $sslmode === 'prefer' || $sslmode === 'disable') {
        return;
    }
    if ($sslmode !== 'require') {
        throw new RuntimeException('Unsupported sslmode "' . $sslmode . '" (use require, prefer or disable)');
    }
    if (vt_db_driver($db) !== 'mysql') {
        // Other databases take it in the DSN, e.g. "pgsql:host=...;sslmode=require".
        throw new RuntimeException('sslmode is only supported for MySQL/MariaDB; put it in the DSN instead');
    }
    $cipher = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
    if ($cipher === false || (string) $cipher[1] === '') {
        throw new RuntimeException('sslmode is "require", but the database connection is not encrypted');
    }
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

    $options = $settings['options'] ?? [];
    if (!is_array($options)) {
        throw new RuntimeException('Database "options" must be an array of PDO options');
    }
    // "+" keeps the numeric PDO option keys (array_merge would renumber them);
    // the options the app relies on can't be overridden.
    $db = new PDO($dsn, $settings['username'] ?? null, $settings['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ] + $options + [
        PDO::ATTR_TIMEOUT => 10,
    ]);
    if (vt_db_driver($db) === 'sqlite') {
        $db->exec('PRAGMA foreign_keys = ON');
    }
    vt_check_sslmode($db, $settings['sslmode'] ?? null);

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

/* Links for email addresses (see vt_email_links in schema.php) ---------------- */

/** Creates a new emailed link for the address and returns its token. Earlier links keep working. */
function vt_create_email_link(string $emailKey): string
{
    $token = vt_new_token();
    vt_query('INSERT INTO vt_email_links (token_hash, email_key, created_at) VALUES (?, ?, ?)',
        [vt_hash_token($token), $emailKey, time()]);
    return $token;
}

function vt_forget_email_link(string $token): void
{
    vt_query('DELETE FROM vt_email_links WHERE token_hash = ?', [vt_hash_token($token)]);
}

/** Whether a link has ever been emailed to this address. */
function vt_email_has_link(string $emailKey): bool
{
    return vt_query('SELECT 1 FROM vt_email_links WHERE email_key = ?', [$emailKey])->fetchColumn() !== false;
}

/**
 * Whether this browser already knows the address: it opened the personal link,
 * or registered with this address before.
 */
function vt_session_knows_email(?string $session, string $emailKey): bool
{
    if ($session === null) {
        return false;
    }
    return vt_query('SELECT 1 FROM vt_session_emails WHERE session_hash = ? AND email_key = ?', [$session, $emailKey])->fetchColumn() !== false
        || vt_query(
            'SELECT 1 FROM vt_session_bookings sb JOIN vt_bookings b ON b.id = sb.booking_id
             WHERE sb.session_hash = ? AND b.email_key = ?',
            [$session, $emailKey]
        )->fetchColumn() !== false;
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
 * Data version: changes whenever shared data changes, so open pages can see
 * cheaply whether they need to reload.
 * ------------------------------------------------------------------------- */

function vt_data_version(): string
{
    $version = vt_query("SELECT value FROM vt_settings WHERE name = 'data_version'")->fetchColumn();
    return $version === false ? '' : (string) $version;
}

/** Marks the shared data as changed. A random value avoids races between simultaneous changes. */
function vt_bump_data_version(): void
{
    $version = vt_new_id();
    $updated = vt_query("UPDATE vt_settings SET value = ? WHERE name = 'data_version'", [$version])->rowCount();
    if ($updated > 0) {
        return;
    }
    try {
        vt_query("INSERT INTO vt_settings (name, value) VALUES ('data_version', ?)", [$version]);
    } catch (PDOException $e) {
        // Created at the same moment by another request.
        vt_query("UPDATE vt_settings SET value = ? WHERE name = 'data_version'", [$version]);
    }
}

/* ---------------------------------------------------------------------------
 * Page texts the admin can edit
 * ------------------------------------------------------------------------- */

// Null means "not set by the admin": the page then shows its default text in the
// visitor's language (keys page.defaultTitle / page.defaultIntro in public/lang/*.js).
const VT_PAGE_TEXT_DEFAULTS = [
    'page_title' => null,
    'page_intro' => null,
];

/** Title and intro text of the page as set by the admin, or null where not set. */
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
        if ($forEmail && vt_mail_method() !== 'log') {
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

/**
 * How mails are sent: 'smtp' (via the 'smtp' settings), 'mail' (PHP's mail()
 * function, i.e. the web server's local mail system) or 'log' (written to the
 * PHP error log, for testing). Default: 'smtp' if SMTP is configured, else 'log'.
 */
function vt_mail_method(): string
{
    $config = vt_config();
    $method = $config['mail_method'] ?? ($config['smtp'] !== null ? 'smtp' : 'log');
    if (!in_array($method, ['smtp', 'mail', 'log'], true)) {
        throw new RuntimeException('mail_method must be "smtp", "mail" or "log"');
    }
    return $method;
}

/* ---------------------------------------------------------------------------
 * Email texts: one file per language in private/lang/ (en.php, de.php, ...),
 * all with the same keys; missing texts fall back to English.
 * ------------------------------------------------------------------------- */

/** The language code if there is a text file for it, otherwise 'en'. */
function vt_mail_language($lang): string
{
    return is_string($lang) && preg_match('/^[a-z]{2}$/', $lang) === 1 && is_file(__DIR__ . "/lang/$lang.php")
        ? $lang : 'en';
}

function vt_mail_texts(string $lang): array
{
    static $cache = [];
    $lang = vt_mail_language($lang);
    if (!isset($cache[$lang])) {
        $english = require __DIR__ . '/lang/en.php';
        $cache[$lang] = $lang === 'en' ? $english : array_replace($english, require __DIR__ . "/lang/$lang.php");
    }
    return $cache[$lang];
}

function vt_fill(string $text, array $params): string
{
    return preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($params) {
        return array_key_exists($match[1], $params) ? (string) $params[$match[1]] : $match[0];
    }, $text);
}

function vt_mail_text(string $lang, string $key, array $params = []): string
{
    $texts = vt_mail_texts($lang);
    return vt_fill(isset($texts[$key]) && is_string($texts[$key]) ? $texts[$key] : $key, $params);
}

/** Sends a plain-text mail (or logs it, see vt_mail_method()). Throws on failure. */
function vt_send_mail(string $toEmail, string $toName, string $subject, string $body): void
{
    $config = vt_config();
    $method = vt_mail_method();
    if ($method === 'log') {
        error_log("[volunteertable] Mail to {$toEmail}: {$subject}\n{$body}");
        return;
    }
    if (vt_normalize_email($config['mail_from']) === null) {
        throw new RuntimeException('mail_from must be configured to send mails');
    }

    if ($method === 'mail') {
        vt_php_mail($config['mail_from'], (string) $config['mail_from_name'], $toEmail, $subject, $body);
        return;
    }
    if ($config['smtp'] === null) {
        throw new RuntimeException('mail_method is "smtp", but no smtp settings are configured');
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

/**
 * Sends a mail with PHP's mail(). The sender is also passed as "-f" to sendmail,
 * so bounces go to mail_from instead of the server's administrators.
 */
function vt_php_mail(string $fromEmail, string $fromName, string $toEmail, string $subject, string $body): void
{
    // The sender ends up on sendmail's command line: only allow plain addresses,
    // so it can never smuggle in further sendmail options.
    if (preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/', $fromEmail) !== 1) {
        throw new RuntimeException('mail_from must be a plain address like name@example.org to send with mail()');
    }

    $headers = [
        'From' => vt_mime_header($fromName) . ' <' . vt_smtp_address($fromEmail) . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
    ];
    $encodedBody = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
    $sent = mail(vt_smtp_address($toEmail), vt_mime_header($subject), $encodedBody, $headers, '-f ' . $fromEmail);
    if (!$sent) {
        throw new RuntimeException('mail() could not hand the mail to the local mail system');
    }
}

/** Sends the admin their login link. Throws on failure. */
function vt_send_admin_mail(array $admin, string $token, string $lang = 'en'): void
{
    $link = vt_admin_link($token, true);
    if ($link === null) {
        throw new RuntimeException('base_url must be configured to send mails');
    }

    vt_send_mail($admin['email'], $admin['name'], vt_mail_text($lang, 'admin.subject'),
        vt_mail_text($lang, 'admin.hello', ['name' => $admin['name']]) . "\n\n"
        . vt_mail_text($lang, 'admin.intro') . "\n\n"
        . "{$link}\n\n"
        . vt_mail_text($lang, 'admin.keepSafe') . "\n");
}

/** E.g. "Wed 11 Nov 2026, 10:00 – 13:00", in the given language. */
function vt_format_event_time(array $event, string $lang = 'en'): string
{
    $start = DateTime::createFromFormat('Y-m-d\TH:i:s', $event['starts_at']);
    $end = DateTime::createFromFormat('Y-m-d\TH:i:s', $event['ends_at']);
    if ($start === false || $end === false) {
        return $event['starts_at'] . ' – ' . $event['ends_at'];
    }
    $locale = vt_mail_texts($lang)['locale'];
    $date = function (DateTime $d) use ($locale): string {
        return vt_fill($locale['date'], [
            'day' => $locale['days'][(int) $d->format('N') - 1],
            'd' => $d->format('j'),
            'month' => $locale['months'][(int) $d->format('n') - 1],
            'year' => $d->format('Y'),
        ]);
    };
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    return $date($start) . ', ' . $start->format('H:i') . ' – '
        . ($sameDay ? '' : $date($end) . ', ') . $end->format('H:i');
}

/**
 * Sends the confirmation with the edit link. Throws on failure.
 * $registeredBy: 'self', 'someone_else' or 'admin'; people registered by others
 * are told so, and that they can cancel if it wasn't intended.
 */
function vt_send_booking_mail(array $booking, array $event, string $mailToken, string $registeredBy = 'self',
    string $lang = 'en'): void
{
    $link = vt_edit_link($mailToken, true);
    if ($link === null) {
        throw new RuntimeException('base_url must be configured to send mails');
    }
    if (!in_array($registeredBy, ['self', 'resend', 'new_browser', 'someone_else', 'admin'], true)) {
        $registeredBy = 'self';
    }

    $text = function (string $key, array $params = []) use ($lang): string {
        return vt_mail_text($lang, $key, $params);
    };
    $responsible = $event['responsible'] ?? null;
    $eventDetails = "  {$event['name']}\n"
        . '  ' . vt_format_event_time($event, $lang) . "\n"
        . "  {$event['location_name']}\n"
        . ($responsible !== null ? '  ' . $text('booking.responsible', ['names' => $responsible]) . "\n" : '');

    if (in_array($registeredBy, ['self', 'resend', 'new_browser'], true)) {
        // Registering yourself mails only at the first registration with an address,
        // in a new browser, and on request, so this mail is about the page: the
        // personal link to all registrations, the event comes second.
        $pageTitle = vt_page_texts()['title'] ?? $text('page.defaultTitle');
        $intro = ['self' => 'personal.intro', 'resend' => 'personal.intro', 'new_browser' => 'personal.introNewBrowser'];
        $eventLabel = ['self' => 'personal.firstRegistration', 'resend' => 'personal.askedFor',
            'new_browser' => 'personal.newRegistration'];
        $subject = $text('personal.subject', ['page' => $pageTitle]);
        $body = $text('booking.hello', ['name' => $booking['name']]) . "\n\n"
            . $text($intro[$registeredBy], ['page' => $pageTitle, 'event' => $event['name']]) . "\n\n"
            . "{$link}\n\n"
            . $text('personal.keep') . "\n\n"
            . $text($eventLabel[$registeredBy]) . "\n\n"
            . $eventDetails . "\n"
            . $text('personal.dontShare') . "\n";
    } else {
        // Registered by someone else or an admin: about this one registration.
        $subject = $text('booking.subject', ['event' => $event['name']]);
        $body = $text('booking.hello', ['name' => $booking['name']]) . "\n\n"
            . $text('booking.intro.' . $registeredBy) . "\n\n"
            . $eventDetails . "\n"
            . $text('booking.notIntended') . "\n\n"
            . $text('booking.linkIntro') . "\n\n"
            . "{$link}\n\n"
            . $text('booking.linkInfo') . "\n";
    }

    vt_send_mail($booking['email'], $booking['name'], $subject, $body);
}
