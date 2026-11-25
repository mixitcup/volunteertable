<?php
declare(strict_types=1);

/*
 * Database schema. Only column types and statements that SQLite, MySQL/MariaDB
 * and PostgreSQL all understand are used; ids are random strings generated in
 * PHP, so no auto-increment syntax is needed.
 *
 * The tables are created automatically on the first request. The example data
 * in private/seed/ is only imported on request (see vt_import_examples).
 */

function vt_schema_statements(string $driver): array
{
    $options = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    return [
        'CREATE TABLE IF NOT EXISTS vt_locations (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            url VARCHAR(2000) NULL,
            info TEXT NULL,
            sort_order INTEGER NOT NULL
        )' . $options,

        // Start and end are local times as written, e.g. "2026-11-09T09:30:00".
        // "responsible": names of the people in charge, as free text.
        // "min_helpers": how many people are needed at least (null: no minimum).
        'CREATE TABLE IF NOT EXISTS vt_events (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            location_id VARCHAR(32) NOT NULL REFERENCES vt_locations (id),
            name VARCHAR(255) NOT NULL,
            starts_at VARCHAR(19) NOT NULL,
            ends_at VARCHAR(19) NOT NULL,
            capacity INTEGER NULL,
            info TEXT NULL,
            url VARCHAR(2000) NULL,
            responsible VARCHAR(500) NULL,
            min_helpers INTEGER NULL
        )' . $options,

        // Each booking has two tokens: the edit token is shown on the page right
        // after booking; the mail token is only sent by email and therefore also
        // proves access to the address (see "open" in api/app.php).
        'CREATE TABLE IF NOT EXISTS vt_bookings (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            event_id VARCHAR(32) NOT NULL REFERENCES vt_events (id),
            name VARCHAR(255) NOT NULL,
            email VARCHAR(254) NOT NULL,
            email_key VARCHAR(254) NOT NULL,
            edit_token_hash CHAR(64) NOT NULL UNIQUE,
            mail_token_hash CHAR(64) NOT NULL UNIQUE,
            created_at BIGINT NOT NULL,
            last_mail_at BIGINT NULL,
            UNIQUE (event_id, email_key)
        )' . $options,

        'CREATE TABLE IF NOT EXISTS vt_sessions (
            id_hash CHAR(64) NOT NULL PRIMARY KEY,
            created_at BIGINT NOT NULL,
            last_seen_at BIGINT NOT NULL
        )' . $options,

        // Bookings made (or opened by edit link) in a browser.
        'CREATE TABLE IF NOT EXISTS vt_session_bookings (
            session_hash CHAR(64) NOT NULL REFERENCES vt_sessions (id_hash),
            booking_id VARCHAR(32) NOT NULL,
            PRIMARY KEY (session_hash, booking_id)
        )' . $options,

        // Email addresses whose bookings all show up in a browser.
        'CREATE TABLE IF NOT EXISTS vt_session_emails (
            session_hash CHAR(64) NOT NULL REFERENCES vt_sessions (id_hash),
            email_key VARCHAR(254) NOT NULL,
            PRIMARY KEY (session_hash, email_key)
        )' . $options,

        // Admins log in on further browsers with the link from their mail.
        'CREATE TABLE IF NOT EXISTS vt_admins (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(254) NOT NULL,
            login_token_hash CHAR(64) NOT NULL UNIQUE,
            created_at BIGINT NOT NULL
        )' . $options,

        // Browsers in which an admin is logged in.
        'CREATE TABLE IF NOT EXISTS vt_session_admins (
            session_hash CHAR(64) NOT NULL PRIMARY KEY REFERENCES vt_sessions (id_hash),
            admin_id VARCHAR(32) NOT NULL REFERENCES vt_admins (id)
        )' . $options,

        // One-off flags. The unique name makes sure that e.g. only one
        // visitor can become the first admin, even with simultaneous requests.
        // Also holds the shared invitation token ("invite_token").
        'CREATE TABLE IF NOT EXISTS vt_settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL
        )' . $options,

        // Browsers that may use the page: they opened the invitation link, an
        // edit link from a registration, or an admin link.
        'CREATE TABLE IF NOT EXISTS vt_session_access (
            session_hash CHAR(64) NOT NULL PRIMARY KEY REFERENCES vt_sessions (id_hash)
        )' . $options,

        // Texts the admin can edit, e.g. "page_title" and "page_intro".
        'CREATE TABLE IF NOT EXISTS vt_texts (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        )' . $options,

        // Links sent by email, one per mail. They belong to an email address, not
        // to a single registration: opening one shows all registrations of that
        // address (also later ones) in the browser, and cancelling a registration
        // doesn't break it.
        'CREATE TABLE IF NOT EXISTS vt_email_links (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            email_key VARCHAR(254) NOT NULL,
            created_at BIGINT NOT NULL
        )' . $options,
    ];
}

/**
 * Columns added to existing tables after their first version, as
 * [table, column, definition]. Databases from before get them on the next request.
 */
function vt_added_columns(): array
{
    return [
        ['vt_events', 'responsible', 'VARCHAR(500) NULL'],
        ['vt_events', 'min_helpers', 'INTEGER NULL'],
    ];
}

function vt_ensure_schema(PDO $db): void
{
    // Checks the most recently added table; all statements are "IF NOT EXISTS",
    // so databases created by an older version just get the missing tables.
    try {
        $db->query('SELECT 1 FROM vt_email_links WHERE 1 = 0');
    } catch (PDOException $e) {
        vt_create_tables($db);
    }

    foreach (vt_added_columns() as $added) {
        [$table, $column, $definition] = $added;
        try {
            $db->query("SELECT $column FROM $table WHERE 1 = 0");
        } catch (PDOException $e) {
            $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
}

function vt_create_tables(PDO $db): void
{
    foreach (vt_schema_statements((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) as $statement) {
        $db->exec($statement);
    }
}

/**
 * Fills an empty page with the example data in private/seed/ (asked when the
 * first admin is set up). Returns whether anything was imported: nothing is if
 * there are locations or events already.
 */
function vt_import_examples(PDO $db): bool
{
    $hasData = $db->query('SELECT COUNT(*) FROM vt_locations')->fetchColumn() > 0 ||
        $db->query('SELECT COUNT(*) FROM vt_events')->fetchColumn() > 0;
    if ($hasData) {
        return false;
    }
    vt_import_seed($db, __DIR__ . '/seed');
    return true;
}

function vt_read_json(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON in ' . $file);
    }
    return $data;
}

/**
 * Whole days to move the example events by, so that their middle day is today.
 * Times of day stay as written.
 */
function vt_seed_shift_days(array $events): int
{
    $days = [];
    foreach ($events as $event) {
        $days[] = substr((string) $event['start'], 0, 10);
        $days[] = substr((string) $event['end'], 0, 10);
    }
    if ($days === []) {
        return 0;
    }
    sort($days);
    $first = new DateTimeImmutable($days[0]);
    $last = new DateTimeImmutable($days[count($days) - 1]);
    $middle = $first->modify(sprintf('+%d days', intdiv((int) $first->diff($last)->days, 2)));
    return (int) $middle->diff(new DateTimeImmutable('today'))->format('%r%a');
}

/**
 * Imports locations.json and events.json (with optional example bookings). The
 * events are moved to around today (see vt_seed_shift_days), unless the
 * configuration sets 'seed_around_today' => false.
 */
function vt_import_seed(PDO $db, string $dir): void
{
    if (!is_file($dir . '/locations.json') || !is_file($dir . '/events.json')) {
        return;
    }
    $locations = vt_read_json($dir . '/locations.json');
    $events = vt_read_json($dir . '/events.json');
    // The examples are moved to around today, so the timetable shows something
    // happening now (unless the configuration keeps the dates as written).
    $shiftDays = (vt_config()['seed_around_today'] ?? true) ? vt_seed_shift_days($events) : 0;
    $shift = function (string $dateTime) use ($shiftDays): string {
        return (new DateTimeImmutable($dateTime))->modify(sprintf('%+d days', $shiftDays))->format('Y-m-d\TH:i:s');
    };

    $db->beginTransaction();
    try {
        $locationIds = [];
        $insertLocation = $db->prepare('INSERT INTO vt_locations (id, name, url, info, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($locations as $index => $location) {
            $id = bin2hex(random_bytes(16));
            $locationIds[$location['name']] = $id;
            $insertLocation->execute([$id, $location['name'], $location['url'] ?? null, $location['info'] ?? null, $index]);
        }

        $insertEvent = $db->prepare(
            'INSERT INTO vt_events (id, location_id, name, starts_at, ends_at, capacity, info, url, responsible, min_helpers)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertBooking = $db->prepare(
            'INSERT INTO vt_bookings (id, event_id, name, email, email_key, edit_token_hash, mail_token_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($events as $event) {
            if (!isset($locationIds[$event['location']])) {
                throw new RuntimeException('Seed event "' . $event['name'] . '" has an unknown location');
            }
            $eventId = bin2hex(random_bytes(16));
            $insertEvent->execute([
                $eventId,
                $locationIds[$event['location']],
                $event['name'],
                $shift($event['start']),
                $shift($event['end']),
                isset($event['capacity']) ? (int) $event['capacity'] : null,
                $event['info'] ?? null,
                $event['url'] ?? null,
                $event['responsible'] ?? null,
                isset($event['minimum']) ? (int) $event['minimum'] : null,
            ]);
            foreach ($event['bookings'] ?? [] as $index => $booking) {
                // Random tokens nobody knows: example bookings can't be edited via link.
                $insertBooking->execute([
                    bin2hex(random_bytes(16)),
                    $eventId,
                    $booking['name'],
                    $booking['email'],
                    strtolower($booking['email']),
                    hash('sha256', random_bytes(32)),
                    hash('sha256', random_bytes(32)),
                    time() - 3600 + $index,
                ]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
