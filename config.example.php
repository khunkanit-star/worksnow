<?php
// Copy to config.local.php to override defaults. Never commit real credentials.
return [
    'dsn' => 'sqlite:' . __DIR__ . '/storage/worksnow.sqlite',
    // MySQL example: mysql:host=127.0.0.1;dbname=worksnow;charset=utf8mb4
    'db_user' => '',
    'db_password' => '',
    'timezone' => 'Asia/Bangkok',
    'currency' => 'THB',
    // Set true behind HTTPS in production.
    'secure_cookies' => false,
];
