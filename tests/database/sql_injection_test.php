<?php

declare(strict_types=1);

require __DIR__ . '/../cli-bootstrap.php';

use Swoole\Coroutine;
use Swiftphp\Framework\Database\DB;

echo "SQL INJECTION TEST SUITE\n";

Coroutine\run(function () {

    DB::statement("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255),
            email VARCHAR(255),
            active BOOLEAN DEFAULT TRUE
        )
    ");

    DB::statement("TRUNCATE TABLE users");

    // Seed safe data
    for ($i = 1; $i <= 50; $i++) {
        DB::table('users')->insert([
            'name' => "User $i",
            'email' => "user$i@test.com",
            'active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MALICIOUS INPUT SET
    |--------------------------------------------------------------------------
    */

    $payloads = [
        "' OR 1=1 --",
        "'; DROP TABLE users; --",
        "\" OR \"\" = \"",
        "' UNION SELECT * FROM users --",
        "' OR SLEEP(1) --",
        "admin'--",
        "' OR 'a'='a",
        "\x00' OR 1=1 --",
    ];

    $failures = 0;

    foreach ($payloads as $payload) {

        try {

            // SELECT
            DB::table('users')
                ->where('email', $payload)
                ->first();

            // INSERT
            DB::table('users')->insert([
                'name' => $payload,
                'email' => 'safe@test.com',
                'active' => true
            ]);

            // UPDATE
            DB::table('users')
                ->where('name', $payload)
                ->update(['active' => false]);

            // DELETE
            DB::table('users')
                ->where('name', $payload)
                ->delete();

        } catch (\Throwable $e) {
            $failures++;
            echo "Blocked payload: {$payload}\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL ASSERTION
    |--------------------------------------------------------------------------
    */

    $count = DB::table('users')->count();

    echo "Remaining users: {$count}\n";
    echo "Injection failures: {$failures}\n";

    echo "SQL injection test completed\n";
});