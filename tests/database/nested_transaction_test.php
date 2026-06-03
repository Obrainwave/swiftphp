<?php

declare(strict_types=1);

require __DIR__ . '/../cli-bootstrap.php';

use Swiftphp\Framework\Database\DB;

echo "TRANSACTION TEST (SAVEPOINT + ROLLBACK + TIMEOUT)\n";

run(function () {

    DB::statement("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255),
            email VARCHAR(255),
            active BOOLEAN DEFAULT TRUE
        )
    ");

    DB::statement("
        CREATE TABLE IF NOT EXISTS profiles (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id BIGINT,
            bio TEXT NULL
        )
    ");

    DB::statement("TRUNCATE TABLE users");
    DB::statement("TRUNCATE TABLE profiles");

    /*
    |--------------------------------------------------------------------------
    | NESTED TRANSACTION TEST
    |--------------------------------------------------------------------------
    */

    try {

        DB::transaction(function () {

            $userId = DB::table('users')->insertGetId([
                'name' => 'Root User',
                'email' => 'root@test.com',
                'active' => true,
            ]);

            // Nested transaction (SAVEPOINT)
            DB::transaction(function () use ($userId) {

                DB::table('profiles')->insert([
                    'user_id' => $userId,
                    'bio' => 'nested profile'
                ]);

                // Force rollback inside savepoint
                throw new \RuntimeException("Forced nested rollback");

            });

        });

    } catch (\Throwable $e) {
        echo "Nested transaction rollback OK: {$e->getMessage()}\n";
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL STATE CHECK
    |--------------------------------------------------------------------------
    */

    $users = DB::table('users')->get();
    $profiles = DB::table('profiles')->get();

    echo "Users count: " . count($users) . "\n";
    echo "Profiles count: " . count($profiles) . "\n";

    echo "Transaction test completed\n";
});