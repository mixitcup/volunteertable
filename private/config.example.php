<?php
// Copy this file to config.php (same folder) and adjust it.
// config.php is ignored by git, so passwords stay out of the repository.
// Without a database configuration, a SQLite database in private/data/ is used
// (for testing). Without SMTP settings, mails are written to the PHP error log.
return [
    // Database, in the same format as the Engelsystem's config/config.php.
    // Instead of this block, the Engelsystem's environment variables can be used:
    // MYSQL_TYPE, MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER and MYSQL_PASSWORD
    // (values given here take precedence over them).
    // All tables start with "vt_", so the Engelsystem's database can be shared.
    // 'database' => [
    //     'driver' => 'mysql',          // 'mysql' or 'mariadb'
    //     'host' => 'localhost',
    //     'database' => 'engelsystem',
    //     'username' => 'engelsystem',
    //     'password' => '',
    //
    //     // Optional: encrypted connection. 'sslmode' => 'require' refuses to work
    //     // over an unencrypted connection; 'options' are passed to PDO.
    //     // 'sslmode' => 'require',
    //     // 'options' => [
    //     //     PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    //     //     PDO::MYSQL_ATTR_SSL_CA => '/path/to/ca-certificates.pem',
    //     // ],
    // ],

    // Alternatively, any PDO connection (takes precedence over 'database'; also
    // accepts 'options' and 'sslmode' like above), e.g.:
    // 'db' => ['dsn' => 'pgsql:host=localhost;dbname=volunteertable', 'username' => 'volunteertable', 'password' => ''],
    // 'db' => ['dsn' => 'sqlite:' . __DIR__ . '/data/volunteertable.sqlite'],

    // Public URL of the folder containing index.htm, without trailing slash.
    // Required for sending real mail: the edit links in mails are built from it.
    'base_url' => 'https://example.org/volunteertable',

    'mail_from' => 'volunteers@example.org',
    'mail_from_name' => 'Volunteer table',

    // Set to null to write mails to the PHP error log instead of sending them.
    'smtp' => [
        'host' => 'smtp.example.org',
        'port' => 587,
        'secure' => 'tls', // 'tls' (STARTTLS, usually port 587), 'ssl' (usually port 465) or 'none'
        'username' => 'volunteers@example.org',
        'password' => '',
    ],
];
