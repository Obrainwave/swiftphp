<?php
// config/database.php — shipped by the framework skeleton
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => env('DB_CONNECTION', 'mysql'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'pool' => [
                'min' => (int) env('DB_POOL_MIN', 5),
                'max' => (int) env('DB_POOL_MAX', 50),
                'timeout' => (float) env('DB_POOL_TIMEOUT', 3.0),
            ],
        ],
        'analytics' => [
            'driver' => env('ANALYTICS_DB_CONNECTION', 'pgsql'),
            'host' => env('ANALYTICS_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => (int) env('ANALYTICS_DB_PORT', env('DB_PORT', 5430)),
            'database' => env('ANALYTICS_DB_DATABASE', env('DB_DATABASE', 'swiftphp')),
            'username' => env('ANALYTICS_DB_USERNAME', env('DB_USERNAME', 'postgres')),
            'password' => env('ANALYTICS_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'pool' => [
                'min' => (int) env('ANALYTICS_DB_POOL_MIN', 5),
                'max' => (int) env('ANALYTICS_DB_POOL_MAX', 50),
                'timeout' => (float) env('ANALYTICS_DB_POOL_TIMEOUT', 3.0),
            ],
        ],
    ],
];