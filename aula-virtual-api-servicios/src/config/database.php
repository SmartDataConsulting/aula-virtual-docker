<?php

return [

    'default' => 'mysql_cursos',

    'migrations' => 'migrations',

    'connections' => [

       'mysql_cursos' => [
            'driver' => 'mysql',
            'host' => env('DB_CURSOS_HOST'),
            'port' => env('DB_CURSOS_PORT', 3306),
            'database' => env('DB_CURSOS_DATABASE'),
            'username' => env('DB_CURSOS_USERNAME'),
            'password' => env('DB_CURSOS_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'options' => [
                PDO::ATTR_TIMEOUT => 3,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ],
        ],

       'mysql_sga' => [
            'driver' => 'mysql',
            'host' => env('DB_SGA_HOST', env('DB_CURSOS_HOST')),
            'port' => env('DB_SGA_PORT', env('DB_CURSOS_PORT', 3306)),
            'database' => env('DB_SGA_DATABASE', env('DB_CURSOS_DATABASE')),
            'username' => env('DB_SGA_USERNAME', env('DB_CURSOS_USERNAME')),
            'password' => env('DB_SGA_PASSWORD', env('DB_CURSOS_PASSWORD')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'options' => [
                PDO::ATTR_TIMEOUT => 3,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ],
        ],

    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_DB', 1),
        ],
    ],
];
