<?php
declare(strict_types=1);

/*
 * Volunteer table API
 *
 *   GET  app.php?action=events  -> { locations: [...], events: [...] }  (with attendee names)
 *   GET  app.php?action=my      -> { bookings: [...], adminExists, admin: {name, email} | null }
 *                                  bookings belonging to this browser, and whether an admin is logged in
 *   POST app.php?action=book    { eventId, name, email } -> { booking, editLink, mailSent }
 *                                 | 409 already_booked | 409 event_full | 404 unknown_event
 *   POST app.php?action=open    { token }            -> { bookingId, emailLinked } | 404 invalid_link
 *   POST app.php?action=update  { bookingId, name }  -> { booking }
 *   POST app.php?action=cancel  { bookingId }        -> { ok }
 *   POST app.php?action=resend  { eventId, email }   -> { ok }
 *
 * Admin:
 *   POST app.php?action=claim_admin  { name, email } -> { admin, adminLink, mailSent } | 409 admin_exists
 *                                      only works while there is no admin yet
 *   POST app.php?action=admin_login  { token }       -> { admin } | 404 invalid_link
 *   GET  app.php?action=admin_users                  -> { users: [{ email, names, bookings }] }
 *   POST app.php?action=admin_remove { bookingId }   -> { ok }
 *   POST app.php?action=admin_remove_user { email }  -> { removed }  all registrations of that address
 *
 * POST bodies must be JSON, which also protects against cross-site form posts.
 * Email addresses are only returned for this browser's own bookings and to admins.
 */

require __DIR__ . '/../private/lib.php';

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
            'name' => $location['name'],
            'url' => $location['url'],
            'info' => $location['info'],
        ];
    }, vt_query('SELECT name, url, info FROM vt_locations ORDER BY sort_order, name')->fetchAll());

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
            respond(200, list_events());
        }
        if ($action === 'my') {
            $session = vt_session(false);
            respond(200, [
                'bookings' => array_map('public_booking', vt_session_bookings($session)),
                'adminExists' => vt_admin_exists(),
                'admin' => public_admin(vt_session_admin($session)),
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
        case 'book':
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

            $session = vt_session(true);
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

        case 'resend':
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
