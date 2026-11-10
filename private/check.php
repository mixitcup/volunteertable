<?php
declare(strict_types=1);

/*
 * Checks a deployment and prints what is wrong. Run on the server:
 *
 *     php private/check.php
 *
 * Run it as (or with the same environment as) the web server user if possible,
 * since file permissions and environment variables can differ. It only runs on
 * the command line, so it reveals nothing if it is reachable from the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$problems = 0;
function ok(string $text): void
{
    echo "  ok    $text\n";
}
function problem(string $text): void
{
    global $problems;
    $problems++;
    echo "  FAIL  $text\n";
}
function note(string $text): void
{
    echo "  info  $text\n";
}

echo "PHP\n";
version_compare(PHP_VERSION, '7.4.0', '>=') ? ok('PHP ' . PHP_VERSION) : problem('PHP ' . PHP_VERSION . ' is too old (7.4 or newer needed)');
foreach (['pdo', 'json', 'openssl'] as $extension) {
    extension_loaded($extension) ? ok("extension $extension") : problem("extension $extension is missing");
}
note('PDO drivers: ' . (class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : 'none'));
if (ini_get('open_basedir')) {
    note('open_basedir: ' . ini_get('open_basedir') . ' (private/ and any SQLite/CA files must be inside it)');
}

echo "\nConfiguration\n";
$file = __DIR__ . '/config.php';
is_file($file) ? ok("found $file") : note('no private/config.php: using SQLite in private/data/ and writing mails to the log');

try {
    require __DIR__ . '/lib.php';
    $config = vt_config();
    ok('config loaded');
} catch (Throwable $e) {
    problem('loading the configuration failed: ' . $e->getMessage());
    exit(1);
}

echo "\nDatabase\n";
$dsn = (string) $config['db']['dsn'];
note('connection: ' . $dsn . ($config['db']['username'] !== null ? ' as user ' . $config['db']['username'] : ''));
if (strpos($dsn, 'sqlite:') === 0) {
    $path = substr($dsn, strlen('sqlite:'));
    $dir = dirname($path);
    if (!is_dir($dir)) {
        note("folder $dir doesn't exist yet; it will be created");
    } elseif (!is_writable($dir)) {
        problem("folder $dir is not writable for user " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user())
            . ' (SQLite needs to write there; the web server user too)');
    }
}
$driver = substr($dsn, 0, (int) strpos($dsn, ':'));
if (class_exists('PDO') && !in_array($driver, PDO::getAvailableDrivers(), true)) {
    problem("PDO driver \"$driver\" is not installed (e.g. php-mysqlnd / pdo_mysql or pdo_sqlite)");
}
try {
    $db = vt_db();
    ok('connected and tables ready (' . vt_db_driver($db) . ')');
    note(vt_query('SELECT COUNT(*) FROM vt_locations')->fetchColumn() . ' locations, '
        . vt_query('SELECT COUNT(*) FROM vt_events')->fetchColumn() . ' events, '
        . vt_query('SELECT COUNT(*) FROM vt_bookings')->fetchColumn() . ' registrations, admin: '
        . (vt_admin_exists() ? 'yes' : 'not yet (the first visitor becomes admin)'));
} catch (Throwable $e) {
    problem('database: ' . $e->getMessage());
}

echo "\nMail\n";
try {
    $method = vt_mail_method();
    note("mail_method: $method");
    if ($method !== 'log') {
        vt_normalize_email($config['mail_from']) !== null ? ok('mail_from: ' . $config['mail_from']) : problem('mail_from is not a valid address');
        rtrim((string) $config['base_url'], '/') !== '' ? ok('base_url: ' . $config['base_url'])
            : problem('base_url is not set: no mails are sent without it (the links in them would be unsafe)');
    }
    if ($method === 'mail') {
        note('sendmail_path: ' . (ini_get('sendmail_path') ?: '(not set)'));
    }
    if ($method === 'smtp' && $config['smtp'] === null) {
        problem('mail_method is smtp, but no smtp settings are configured');
    }
} catch (Throwable $e) {
    problem($e->getMessage());
}

echo "\nPHP error output\n";
ini_get('display_errors') && ini_get('display_errors') !== 'Off' && ini_get('display_errors') !== '0'
    ? note('display_errors is on for the command line; make sure it is off on the web server')
    : ok('display_errors is off');
note('error_log: ' . (ini_get('error_log') ?: '(web server default; errors from the app start with "[volunteertable]")'));

echo $problems === 0 ? "\nNo problems found.\n" : "\n$problems problem(s) found.\n";
exit($problems === 0 ? 0 : 1);
