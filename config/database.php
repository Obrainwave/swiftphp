<?php
// config/database.php — shipped by the framework skeleton
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
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
            'driver' => 'mysql',
            'host' => env('DB_ANALYTICS_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => (int) env('DB_ANALYTICS_PORT', env('DB_PORT', 3306)),
            'database' => env('DB_ANALYTICS_DATABASE', env('DB_DATABASE', 'swiftphp')), // Or a separate DB if you prefer
            'username' => env('DB_ANALYTICS_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_ANALYTICS_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'pool' => [
                'min' => (int) env('DB_POOL_MIN', 5),
                'max' => (int) env('DB_POOL_MAX', 50),
                'timeout' => (float) env('DB_POOL_TIMEOUT', 3.0),
            ],
        ],
    ],
];