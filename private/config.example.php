<?php
// Copy this file to config.php (same folder) and adjust it.
// config.php is ignored by git, so passwords stay out of the repository.
// Without a config.php, a SQLite database in private/data/ is used and mails
// are written to the PHP error log instead of being sent.
return [
    'db' => [
        // SQLite (for testing): the file is created automatically.
        'dsn' => 'sqlite:' . __DIR__ . '/data/volunteertable.sqlite',
        'username' => null,
        'password' => null,

        // MySQL / MariaDB:
        // 'dsn' => 'mysql:host=localhost;dbname=volunteertable;charset=utf8mb4',
        // 'username' => 'volunteertable',
        // 'password' => '',

        // PostgreSQL:
        // 'dsn' => 'pgsql:host=localhost;dbname=volunteertable',
        // 'username' => 'volunteertable',
        // 'password' => '',
    ],

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
