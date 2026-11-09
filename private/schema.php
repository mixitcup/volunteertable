<?php
declare(strict_types=1);

/*
 * Database schema. Only column types and statements that SQLite, MySQL/MariaDB
 * and PostgreSQL all understand are used; ids are random strings generated in
 * PHP, so no auto-increment syntax is needed.
 *
 * The tables are created automatically on the first request. If the database
 * is empty, the example data in private/seed/ is imported.
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
        'CREATE TABLE IF NOT EXISTS vt_events (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            location_id VARCHAR(32) NOT NULL REFERENCES vt_locations (id),
            name VARCHAR(255) NOT NULL,
            starts_at VARCHAR(19) NOT NULL,
            ends_at VARCHAR(19) NOT NULL,
            capacity INTEGER NULL,
            info TEXT NULL,
            url VARCHAR(2000) NULL
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
        'CREATE TABLE IF NOT EXISTS vt_settings (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL
        )' . $options,
    ];
}

function vt_ensure_schema(PDO $db): void
{
    // Checks the most recently added table; all statements are "IF NOT EXISTS",
    // so databases created by an older version just get the missing tables.
    try {
        $db->query('SELECT 1 FROM vt_settings WHERE 1 = 0');
        return;
    } catch (PDOException $e) {
        // Tables missing: create them below.
    }

    foreach (vt_schema_statements((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) as $statement) {
        $db->exec($statement);
    }

    $hasData = $db->query('SELECT COUNT(*) FROM vt_locations')->fetchColumn() > 0;
    if (!$hasData) {
        vt_import_seed($db, __DIR__ . '/seed');
    }
}

function vt_read_json(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON in ' . $file);
    }
    return $data;
}

/** Imports locations.json and events.json (with optional example bookings). */
function vt_import_seed(PDO $db, string $dir): void
{
    if (!is_file($dir . '/locations.json') || !is_file($dir . '/events.json')) {
        return;
    }
    $locations = vt_read_json($dir . '/locations.json');
    $events = vt_read_json($dir . '/events.json');

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
            'INSERT INTO vt_events (id, location_id, name, starts_at, ends_at, capacity, info, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
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
                $event['start'],
                $event['end'],
                isset($event['capacity']) ? (int) $event['capacity'] : null,
                $event['info'] ?? null,
                $event['url'] ?? null,
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
