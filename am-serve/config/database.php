<?php

return [
    'default' => env('DB_TYPE', 'mysql'),
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'host' => getenv('DB_HOSTNAME', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' =>  env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' =>env('DB_PREFIX', 'am_'),
            'strict' => true,
            'engine' => 'InnoDB',
        ],
    ],
];
