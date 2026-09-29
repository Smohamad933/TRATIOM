<?php

declare(strict_types=1);

$driver = (string) env('DB_CONNECTION', 'mysql');
$sqlitePath = (string) env('DB_DATABASE', '');

return [
    'driver' => $driver,
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => $driver === 'sqlite'
        ? ($sqlitePath !== '' && $sqlitePath !== 'terrarium_db' ? $sqlitePath : dirname(__DIR__) . '/storage/database/terrarium.sqlite')
        : $sqlitePath,
    'username' => env('DB_USERNAME', ''),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
];
