<?php
declare(strict_types=1);

/*
 * Volunteer table API
 *
 * Access: only browsers that opened the shared invitation link (index.htm?invite=TOKEN),
 * an edit link of a registration or an admin link may see events and register.
 * Actions marked [access] answer 403 access_required otherwise.
 *
 *   POST app.php?action=join    { token }            -> { ok } | 404 invalid_link  (invitation link)
 *   GET  app.php?action=events  -> { locations: [...], events: [...] }  (with attendee names) [access]
 *   GET  app.php?action=my      -> { access, bookings: [...], adminExists, admin: {name, email} | null,
 *                                    inviteLink (admins only) }
 *                                  bookings belonging to this browser, and whether an admin is logged in
 *   POST app.php?action=book    { eventId, name, email } -> { booking, editLink, mailSent } [access]
 *                                 | 409 already_booked | 409 event_full | 404 unknown_event
 *   POST app.php?action=open    { token }            -> { bookingId, emailLinked } | 404 invalid_link
 *                                                       (also gives this browser access)
 *   POST app.php?action=update  { bookingId, name }  -> { booking }
 *   POST app.php?action=cancel  { bookingId }        -> { ok }
 *   POST app.php?action=resend  { eventId, email }   -> { ok } [access]
 *
 * Admin:
 *   POST app.php?action=claim_admin  { name, email } -> { admin, adminLink, mailSent } | 409 admin_exists
 *                                      only works while there is no admin yet (no invitation needed)
 *   POST app.php?action=admin_login  { token }       -> { admin } | 404 invalid_link
 *   GET  app.php?action=admin_users                  -> { users: [{ email, names, bookings }] }
 *   POST app.php?action=admin_remove { bookingId }   -> { ok }
 *   POST app.php?action=admin_remove_user { email }  -> { removed }  all registrations of that address
 *   POST app.php?action=admin_save_location { id?, name, url, info }  -> { id }  creates without id
 *   POST app.php?action=admin_delete_location { id } -> { ok } | 409 location_has_events
 *   POST app.php?action=admin_save_event { id?, name, locationId, start, end, capacity, info, url } -> { id }
 *                                      start/end as "YYYY-MM-DDTHH:MM"; capacity null = unlimited
 *   POST app.php?action=admin_delete_event { id }    -> { removedRegistrations }
 *
 * POST bodies must be JSON, which also protects against cross-site form posts.
 * Email addresses are only returned for this browser's own bookings and to admins.
 */

// The private folder (code, config, database) lives next to public/, outside the
// web root. __DIR__ has symlinks resolved, so this also works when public/ is
// symlinked into the web server's folder. If public/ is copied instead, set the
// environment variable VOLUNTEERTABLE_PRIVATE_DIR to the private folder.
$privateDir = getenv('VOLUNTEERTABLE_PRIVATE_DIR') ?: __DIR__ . '/../../private';
require $privateDir . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function public_booking(array $booking): array
{
    return [
        'id' => $booking['id'],
        'eventId' => $booking['event_id'],
        'name' => $booking['name'],
        'email' => $booking['email'],
    ];
}

function find_event(string $eventId, bool $lock = false): ?array
{
    $event = vt_query(
        'SELECT e.*, l.name AS location_name FROM vt_events e JOIN vt_locations l ON l.id = e.location_id WHERE e.id = ?'
            . ($lock ? vt_for_update() : ''),
        [$eventId]
    )->fetch();
    return $event === false ? null : $event;
}

function list_events(): array
{
    $locations = array_map(function (array $location) {
        return [
            'id' => $location['id'],
            'name' => $location['name'],
            'url' => $location['url'],
            'info' => $location['info'],
        ];
    }, vt_query('SELECT id, name, url, info FROM vt_locations ORDER BY sort_order, name')->fetchAll());

    $attendees = [];
    foreach (vt_query('SELECT event_id, name FROM vt_bookings ORDER BY created_at, name')->fetchAll() as $booking) {
        $attendees[$booking['event_id']][] = $booking['name'];
    }

    $events = array_map(function (array $event) use ($attendees) {
        $names = $attendees[$event['id']] ?? [];
        return [
            'id' => $event['id'],
            'name' => $event['name'],
            'location' => $event['location_name'],
            'locationId' => $event['location_id'],
            'start' => $event['starts_at'],
            'end' => $event['ends_at'],
            'capacity' => $event['capacity'] === null ? null : (int) $event['capacity'],
            'info' => $event['info'],
            'url' => $event['url'],
            'registered' => count($names),
            'attendees' => $names,
        ];
    }, vt_query(
        'SELECT e.*, l.name AS location_name FROM vt_events e JOIN vt_locations l ON l.id = e.location_id
         ORDER BY e.starts_at, e.name'
    )->fetchAll());

    return ['locations' => $locations, 'events' => $events];
}

function public_admin(?array $admin): ?array
{
    return $admin === null ? null : ['name' => $admin['name'], 'email' => $admin['email']];
}

/** Only browsers that opened the invitation link (or an edit/admin link) may continue. */
function require_access(): string
{
    $session = vt_session(false);
    if (!vt_has_access($session)) {
        respond(403, ['error' => 'access_required']);
    }
    return $session;
}

function require_admin(): array
{
    $admin = vt_session_admin(vt_session(false));
    if ($admin === null) {
        respond(403, ['error' => 'admin_required']);
    }
    return $admin;
}

/** Everyone who registered, grouped by email address, with their bookings. */
function list_users(): array
{
    $rows = vt_query(
        'SELECT b.id, b.event_id, b.name, b.email, b.email_key, b.created_at
         FROM vt_bookings b JOIN vt_events e ON e.id = b.event_id
         ORDER BY e.starts_at, e.name'
    )->fetchAll();

    $users = [];
    foreach ($rows as $row) {
        $key = $row['email_key'];
        if (!isset($users[$key])) {
            $users[$key] = ['email' => $row['email'], 'names' => [], 'bookings' => []];
        }
        if (!in_array($row['name'], $users[$key]['names'], true)) {
            $users[$key]['names'][] = $row['name'];
        }
        $users[$key]['bookings'][] = [
            'id' => $row['id'],
            'eventId' => $row['event_id'],
            'name' => $row['name'],
            'created' => (int) $row['created_at'],
        ];
    }

    $users = array_values($users);
    usort($users, function (array $a, array $b) {
        return strcasecmp($a['names'][0], $b['names'][0]) ?: strcasecmp($a['email'], $b['email']);
    });
    return $users;
}

/* Validation for the admin's location and event forms. Each returns the clean value or responds with an error. */

function input_name(array $input, string $key): string
{
    $name = vt_normalize_name($input[$key] ?? null);
    if ($name === null) {
        respond(400, ['error' => 'invalid_name', 'field' => $key]);
    }
    return $name;
}

function input_text(array $input, string $key, int $maxLength): ?string
{
    $value = $input[$key] ?? null;
    if ($value === null) {
        return null;
    }
    if (!is_string($value) || preg_match('//u', $value) !== 1 || strlen($value) > $maxLength) {
        respond(400, ['error' => 'invalid_text', 'field' => $key]);
    }
    $value = trim(str_replace("\r\n", "\n", $value));
    return $value === '' ? null : $value;
}

/** Web links: http(s), mailto, or a relative link such as "#" or "/page". Never javascript: etc. */
function input_url(array $input, string $key): ?string
{
    $url = input_text($input, $key, 2000);
    if ($url === null) {
        return null;
    }
    if (preg_match('/\s/', $url) === 1
        || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $match) === 1
            && !in_array(strtolower($match[1]), ['http', 'https', 'mailto'], true))) {
        respond(400, ['error' => 'invalid_url', 'field' => $key]);
    }
    return $url;
}

/** Local date and time as "YYYY-MM-DDTHH:MM" (from a datetime-local input), stored with seconds. */
function input_datetime(array $input, string $key): string
{
    $value = $input[$key] ?? null;
    $date = is_string($value) ? DateTime::createFromFormat('!Y-m-d\TH:i', substr($value, 0, 16)) : false;
    if ($date === false || $date->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
        respond(400, ['error' => 'invalid_datetime', 'field' => $key]);
    }
    return $date->format('Y-m-d\TH:i:s');
}

function require_own_booking(array $input): string
{
    $bookingId = $input['bookingId'] ?? null;
    if (!is_string($bookingId) || !vt_session_owns_booking(vt_session(false), $bookingId)) {
        respond(404, ['error' => 'unknown_booking']);
    }
    return $bookingId;
}

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        if ($action === 'events') {
            require_access();
            respond(200, list_events());
        }
        if ($action === 'my') {
            $session = vt_session(false);
            $access = vt_has_access($session);
            $admin = vt_session_admin($session);
            respond(200, [
                'access' => $access,
                'bookings' => $access ? array_map('public_booking', vt_session_bookings($session)) : [],
                'adminExists' => vt_admin_exists(),
                'admin' => public_admin($admin),
                // Only admins get the shared invitation link to pass on.
                'inviteLink' => $admin !== null ? vt_invite_link() : null,
            ]);
        }
        if ($action === 'admin_users') {
            require_admin();
            respond(200, ['users' => list_users()]);
        }
        respond(404, ['error' => 'unknown_action']);
    }

    if ($method !== 'POST') {
        respond(405, ['error' => 'method_not_allowed']);
    }
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        respond(415, ['error' => 'json_required']);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['error' => 'invalid_json']);
    }

    switch ($action) {
        case 'join':
            // Opening the shared invitation link gives this browser access.
            $token = $input['token'] ?? null;
            if (!vt_is_token_format($token) || !hash_equals(vt_invite_token(), $token)) {
                respond(404, ['error' => 'invalid_link']);
            }
            vt_grant_access();
            respond(200, ['ok' => true]);

        case 'book':
            require_access();
            $eventId = is_string($input['eventId'] ?? null) ? $input['eventId'] : '';
            $name = vt_normalize_name($input['name'] ?? null);
            $email = vt_normalize_email($input['email'] ?? null);
            if ($name === null) {
                respond(400, ['error' => 'invalid_name']);
            }
            if ($email === null) {
                respond(400, ['error' => 'invalid_email']);
            }

            $editToken = vt_new_token();
            $mailToken = vt_new_token();
            $result = vt_transaction(function () use ($eventId, $name, $email, $editToken, $mailToken) {
                $event = find_event($eventId, true);
                if ($event === null) {
                    return ['error' => 'unknown_event'];
                }
                $emailKey = vt_email_key($email);
                $existing = vt_query('SELECT 1 FROM vt_bookings WHERE event_id = ? AND email_key = ?', [$eventId, $emailKey])->fetchColumn();
                if ($existing !== false) {
                    return ['error' => 'already_booked'];
                }
                $count = (int) vt_query('SELECT COUNT(*) FROM vt_bookings WHERE event_id = ?', [$eventId])->fetchColumn();
                if ($event['capacity'] !== null && $count >= (int) $event['capacity']) {
                    return ['error' => 'event_full'];
                }

                $booking = [
                    'id' => vt_new_id(),
                    'event_id' => $eventId,
                    'name' => $name,
                    'email' => $email,
                ];
                vt_query(
                    'INSERT INTO vt_bookings (id, event_id, name, email, email_key, edit_token_hash, mail_token_hash, created_at, last_mail_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$booking['id'], $eventId, $name, $email, $emailKey, vt_hash_token($editToken), vt_hash_token($mailToken), time(), time()]
                );
                return ['booking' => $booking, 'event' => $event];
            });

            if (isset($result['error'])) {
                respond($result['error'] === 'unknown_event' ? 404 : 409, ['error' => $result['error']]);
            }

            // Only this booking is linked to the browser, not the email address:
            // nobody has proven yet that they can read mail sent to it.
            vt_insert_link('vt_session_bookings', ['session_hash' => vt_session(true), 'booking_id' => $result['booking']['id']]);

            $mailSent = true;
            try {
                vt_send_booking_mail($result['booking'], $result['event'], $mailToken);
            } catch (Throwable $e) {
                error_log('[volunteertable] Could not send booking mail: ' . $e->getMessage());
                $mailSent = false;
            }
            respond(201, [
                'booking' => public_booking($result['booking']),
                'editLink' => vt_edit_link($editToken, false),
                'mailSent' => $mailSent,
            ]);

        case 'open':
            $token = $input['token'] ?? null;
            if (!vt_is_token_format($token)) {
                respond(404, ['error' => 'invalid_link']);
            }
            $hash = vt_hash_token($token);
            $booking = vt_query(
                'SELECT id, email_key, mail_token_hash FROM vt_bookings WHERE edit_token_hash = ? OR mail_token_hash = ?',
                [$hash, $hash]
            )->fetch();
            if ($booking === false) {
                respond(404, ['error' => 'invalid_link']);
            }

            // Whoever has a registration's edit link was invited before.
            $session = vt_grant_access();
            vt_insert_link('vt_session_bookings', ['session_hash' => $session, 'booking_id' => $booking['id']]);
            // The mail link proves access to the address, so all of its bookings
            // (including future ones) show up under "My events" in this browser.
            $fromMail = hash_equals($booking['mail_token_hash'], $hash);
            if ($fromMail) {
                vt_insert_link('vt_session_emails', ['session_hash' => $session, 'email_key' => $booking['email_key']]);
            }
            respond(200, ['bookingId' => $booking['id'], 'emailLinked' => $fromMail]);

        case 'update':
            $bookingId = require_own_booking($input);
            $name = vt_normalize_name($input['name'] ?? null);
            if ($name === null) {
                respond(400, ['error' => 'invalid_name']);
            }
            vt_query('UPDATE vt_bookings SET name = ? WHERE id = ?', [$name, $bookingId]);
            $booking = vt_query('SELECT id, event_id, name, email FROM vt_bookings WHERE id = ?', [$bookingId])->fetch();
            respond(200, ['booking' => public_booking($booking)]);

        case 'cancel':
            vt_delete_booking(require_own_booking($input));
            respond(200, ['ok' => true]);

        case 'claim_admin':
            $name = vt_normalize_name($input['name'] ?? null);
            $email = vt_normalize_email($input['email'] ?? null);
            if ($name === null) {
                respond(400, ['error' => 'invalid_name']);
            }
            if ($email === null) {
                respond(400, ['error' => 'invalid_email']);
            }

            $token = vt_new_token();
            $admin = ['id' => vt_new_id(), 'name' => $name, 'email' => $email];
            try {
                vt_transaction(function () use ($admin, $token) {
                    if (vt_admin_exists()) {
                        throw new DomainException('admin_exists');
                    }
                    // Fails with a duplicate key if another visitor claimed it at the same moment.
                    vt_query("INSERT INTO vt_settings (name, value) VALUES ('first_admin', ?)", [$admin['id']]);
                    vt_query(
                        'INSERT INTO vt_admins (id, name, email, login_token_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                        [$admin['id'], $admin['name'], $admin['email'], vt_hash_token($token), time()]
                    );
                });
            } catch (DomainException | PDOException $e) {
                if ($e instanceof DomainException || vt_admin_exists()) {
                    respond(409, ['error' => 'admin_exists']);
                }
                throw $e;
            }
            vt_login_admin($admin['id']);

            $mailSent = true;
            try {
                vt_send_admin_mail($admin, $token);
            } catch (Throwable $e) {
                error_log('[volunteertable] Could not send admin mail: ' . $e->getMessage());
                $mailSent = false;
            }
            respond(201, [
                'admin' => public_admin($admin),
                'adminLink' => vt_admin_link($token, false),
                'mailSent' => $mailSent,
            ]);

        case 'admin_login':
            $token = $input['token'] ?? null;
            $admin = vt_is_token_format($token)
                ? vt_query('SELECT id, name, email FROM vt_admins WHERE login_token_hash = ?', [vt_hash_token($token)])->fetch()
                : false;
            if ($admin === false) {
                respond(404, ['error' => 'invalid_link']);
            }
            vt_login_admin($admin['id']);
            respond(200, ['admin' => public_admin($admin)]);

        case 'admin_remove':
            require_admin();
            $bookingId = $input['bookingId'] ?? null;
            if (!is_string($bookingId) || vt_query('SELECT 1 FROM vt_bookings WHERE id = ?', [$bookingId])->fetchColumn() === false) {
                respond(404, ['error' => 'unknown_booking']);
            }
            vt_delete_booking($bookingId);
            respond(200, ['ok' => true]);

        case 'admin_remove_user':
            require_admin();
            $email = $input['email'] ?? null;
            if (!is_string($email) || trim($email) === '') {
                respond(400, ['error' => 'invalid_email']);
            }
            $emailKey = vt_email_key(trim($email));
            $removed = vt_transaction(function () use ($emailKey) {
                $bookingIds = vt_query('SELECT id FROM vt_bookings WHERE email_key = ?', [$emailKey])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($bookingIds as $bookingId) {
                    vt_query('DELETE FROM vt_session_bookings WHERE booking_id = ?', [$bookingId]);
                }
                vt_query('DELETE FROM vt_bookings WHERE email_key = ?', [$emailKey]);
                // Browsers linked to the address via an emailed link forget it as well.
                vt_query('DELETE FROM vt_session_emails WHERE email_key = ?', [$emailKey]);
                return count($bookingIds);
            });
            if ($removed === 0) {
                respond(404, ['error' => 'unknown_user']);
            }
            respond(200, ['removed' => $removed]);

        case 'admin_save_location':
            require_admin();
            $id = $input['id'] ?? null;
            $location = [
                'name' => input_name($input, 'name'),
                'url' => input_url($input, 'url'),
                'info' => input_text($input, 'info', 5000),
            ];
            $result = vt_transaction(function () use ($id, $location) {
                // Location names identify rows in the timetable, so they must be unique.
                foreach (vt_query('SELECT id, name FROM vt_locations')->fetchAll() as $other) {
                    if ($other['id'] !== $id && strcasecmp($other['name'], $location['name']) === 0) {
                        return ['error' => 'name_taken'];
                    }
                }
                if ($id === null) {
                    $id = vt_new_id();
                    $order = (int) vt_query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM vt_locations')->fetchColumn();
                    vt_query(
                        'INSERT INTO vt_locations (id, name, url, info, sort_order) VALUES (?, ?, ?, ?, ?)',
                        [$id, $location['name'], $location['url'], $location['info'], $order]
                    );
                    return ['id' => $id];
                }
                if (!is_string($id) || vt_query('SELECT 1 FROM vt_locations WHERE id = ?', [$id])->fetchColumn() === false) {
                    return ['error' => 'unknown_location'];
                }
                vt_query(
                    'UPDATE vt_locations SET name = ?, url = ?, info = ? WHERE id = ?',
                    [$location['name'], $location['url'], $location['info'], $id]
                );
                return ['id' => $id];
            });
            if (isset($result['error'])) {
                respond($result['error'] === 'unknown_location' ? 404 : 409, $result);
            }
            respond($id === null ? 201 : 200, $result);

        case 'admin_delete_location':
            require_admin();
            $id = $input['id'] ?? null;
            $result = vt_transaction(function () use ($id) {
                if (!is_string($id) || vt_query('SELECT 1 FROM vt_locations WHERE id = ?', [$id])->fetchColumn() === false) {
                    return 'unknown_location';
                }
                // Events are never deleted as a side effect; they have to be moved or deleted first.
                if (vt_query('SELECT 1 FROM vt_events WHERE location_id = ?', [$id])->fetchColumn() !== false) {
                    return 'location_has_events';
                }
                vt_query('DELETE FROM vt_locations WHERE id = ?', [$id]);
                return null;
            });
            if ($result !== null) {
                respond($result === 'unknown_location' ? 404 : 409, ['error' => $result]);
            }
            respond(200, ['ok' => true]);

        case 'admin_save_event':
            require_admin();
            $id = $input['id'] ?? null;
            $event = [
                'name' => input_name($input, 'name'),
                'location_id' => is_string($input['locationId'] ?? null) ? $input['locationId'] : '',
                'starts_at' => input_datetime($input, 'start'),
                'ends_at' => input_datetime($input, 'end'),
                'info' => input_text($input, 'info', 5000),
                'url' => input_url($input, 'url'),
                'capacity' => $input['capacity'] ?? null,
            ];
            if ($event['ends_at'] <= $event['starts_at']) {
                respond(400, ['error' => 'end_before_start', 'field' => 'end']);
            }
            if ($event['capacity'] !== null && (!is_int($event['capacity']) || $event['capacity'] < 1 || $event['capacity'] > 100000)) {
                respond(400, ['error' => 'invalid_capacity', 'field' => 'capacity']);
            }

            $result = vt_transaction(function () use ($id, $event) {
                if (vt_query('SELECT 1 FROM vt_locations WHERE id = ?', [$event['location_id']])->fetchColumn() === false) {
                    return ['error' => 'unknown_location', 'field' => 'locationId'];
                }
                $values = [$event['name'], $event['location_id'], $event['starts_at'], $event['ends_at'],
                    $event['capacity'], $event['info'], $event['url']];
                if ($id === null) {
                    $id = vt_new_id();
                    vt_query(
                        'INSERT INTO vt_events (name, location_id, starts_at, ends_at, capacity, info, url, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        array_merge($values, [$id])
                    );
                    return ['id' => $id];
                }
                if (!is_string($id) || find_event($id, true) === null) {
                    return ['error' => 'unknown_event'];
                }
                // Lowering the capacity below the number of registrations would make
                // "places left" negative; people have to be removed first.
                $registered = (int) vt_query('SELECT COUNT(*) FROM vt_bookings WHERE event_id = ?', [$id])->fetchColumn();
                if ($event['capacity'] !== null && $event['capacity'] < $registered) {
                    return ['error' => 'capacity_below_registrations', 'field' => 'capacity', 'registered' => $registered];
                }
                vt_query(
                    'UPDATE vt_events SET name = ?, location_id = ?, starts_at = ?, ends_at = ?, capacity = ?, info = ?, url = ? WHERE id = ?',
                    array_merge($values, [$id])
                );
                return ['id' => $id];
            });
            if (isset($result['error'])) {
                respond($result['error'] === 'unknown_event' ? 404 : 409, $result);
            }
            respond($id === null ? 201 : 200, $result);

        case 'admin_delete_event':
            require_admin();
            $id = $input['id'] ?? null;
            $removed = vt_transaction(function () use ($id) {
                if (!is_string($id) || find_event($id, true) === null) {
                    return null;
                }
                $bookingIds = vt_query('SELECT id FROM vt_bookings WHERE event_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($bookingIds as $bookingId) {
                    vt_query('DELETE FROM vt_session_bookings WHERE booking_id = ?', [$bookingId]);
                }
                vt_query('DELETE FROM vt_bookings WHERE event_id = ?', [$id]);
                vt_query('DELETE FROM vt_events WHERE id = ?', [$id]);
                return count($bookingIds);
            });
            if ($removed === null) {
                respond(404, ['error' => 'unknown_event']);
            }
            respond(200, ['removedRegistrations' => $removed]);

        case 'resend':
            require_access();
            $eventId = is_string($input['eventId'] ?? null) ? $input['eventId'] : '';
            $email = vt_normalize_email($input['email'] ?? null);
            if ($email === null) {
                respond(400, ['error' => 'invalid_email']);
            }

            $mailToken = vt_new_token();
            $result = vt_transaction(function () use ($eventId, $email, $mailToken) {
                $booking = vt_query(
                    'SELECT id, event_id, name, email, last_mail_at FROM vt_bookings WHERE event_id = ? AND email_key = ?' . vt_for_update(),
                    [$eventId, vt_email_key($email)]
                )->fetch();
                if ($booking === false || (int) $booking['last_mail_at'] + VT_RESEND_INTERVAL > time()) {
                    return null;
                }
                // A new mail link replaces the previous one.
                vt_query(
                    'UPDATE vt_bookings SET mail_token_hash = ?, last_mail_at = ? WHERE id = ?',
                    [vt_hash_token($mailToken), time(), $booking['id']]
                );
                return $booking;
            });

            if ($result !== null) {
                try {
                    vt_send_booking_mail($result, find_event($eventId), $mailToken);
                } catch (Throwable $e) {
                    error_log('[volunteertable] Could not send booking mail: ' . $e->getMessage());
                    respond(502, ['error' => 'mail_failed']);
                }
            }
            // Same answer whether or not the address is registered (or was mailed
            // a moment ago), so this can't be used to find out who registered.
            respond(200, ['ok' => true]);

        default:
            respond(404, ['error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('[volunteertable] ' . $e);
    respond(500, ['error' => 'server_error']);
}
