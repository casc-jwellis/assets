<?php
/**
 * Copy this file to config/config.php and fill in your values.
 * config/config.php is git-ignored; never commit real credentials.
 *
 * To keep the config elsewhere, set the ASSETS_CONFIG environment variable
 * to the full path of the file.
 */

return [
    'app' => [
        'name'  => 'Asset Manager',
        // Show exception details in the browser. Keep false in production.
        'debug' => false,
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'assets',
        'user'    => 'assets_app',
        'pass'    => '',
        'charset' => 'utf8mb4',
        // Optional full PDO DSN; overrides host/port/name/charset when set.
        'dsn'     => null,
    ],

    'session' => [
        'name'         => 'assets_session',
        // Seconds of inactivity before the user is logged out.
        'idle_timeout' => 1800,
        // null = auto-detect HTTPS. Set true if TLS is terminated by a proxy.
        'secure'       => null,
    ],
];
