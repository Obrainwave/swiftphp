<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Runtime;
use Swoole\Coroutine\WaitGroup;

use Swiftphp\Framework\Database\DB;
use Swiftphp\Framework\Database\Pool\MysqlPool;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/
$config = [
    'host' => '127.0.0.1',
    'port' => 3305,
    'database' => 'swiftphp',
    'user' => 'root',
    'password' => 'secret',
];

run(function () use ($config) {
    $poolMax = 50;

    $pool = new MysqlPool(
        config: $config,
        min: 10,
        max: $poolMax,
        timeout: 3.0
    );

    $db = new DB($pool);

    echo "====================================================\n";
    echo "SWIFTPHP ASYNC QUERY BUILDER BENCHMARK (CLEAN FIX)\n";
    echo "====================================================\n\n";

    /*
    |--------------------------------------------------------------------------
    | SCHEMA
    |--------------------------------------------------------------------------
    */
    $db->statement("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255),
            email VARCHAR(255),
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->statement("
        CREATE TABLE IF NOT EXISTS profiles (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id BIGINT,
            bio TEXT NULL
        )
    ");

    $db->statement("TRUNCATE TABLE profiles");
    $db->statement("TRUNCATE TABLE users");

    /*
    |--------------------------------------------------------------------------
    | SEED
    |--------------------------------------------------------------------------
    */
    for ($i = 1; $i <= 300; $i++) {
        $db->statement(
            "INSERT INTO users(name, email, active) VALUES (?, ?, ?)",
            ["User $i", "u$i@test.com", true]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BENCHMARK
    |--------------------------------------------------------------------------
    */
    $concurrency = 1000;

    $wg = new WaitGroup();
    $wg->add($concurrency);

    $errors = 0;

    $queryTime = array_fill(0, $concurrency, 0.0);
    $txnTime = array_fill(0, $concurrency, 0.0);

    $firstError = null;

    $start = microtime(true);

    for ($i = 0; $i < $concurrency; $i++) {

        Coroutine::create(function () use ($db, $wg, &$errors, &$queryTime, &$txnTime, $i, &$firstError) {

            try {

                /*
                |--------------------------------------------------------------------------
                | SELECT TEST (SAFE FORM)
                |--------------------------------------------------------------------------
                */
                $t0 = microtime(true);

                $db->table('users')
                    ->select('id', 'name', 'email')
                    ->where('active', true)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();

                $queryTime[$i] = microtime(true) - $t0;

                /*
                |--------------------------------------------------------------------------
                | FIRST TEST
                |--------------------------------------------------------------------------
                */
                $db->table('users')
                    ->where('id', random_int(1, 300))
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | TRANSACTION TEST (FIXED PROPERLY)
                |--------------------------------------------------------------------------
                */
                $t1 = microtime(true);

                $db->transaction(function () use ($db, $i) {

                    $userId = $db->table('users')->insertGetId([
                        'name' => "Txn $i",
                        'email' => "txn$i@test.com",
                        'active' => true,
                    ]);

                    $db->table('profiles')->insert([
                        'user_id' => $userId,
                        'bio' => 'profile'
                    ]);
                });

                $txnTime[$i] = microtime(true) - $t1;

            } catch (\Throwable $e) {

                $errors++;

                if ($firstError === null) {
                    $firstError = $e->getMessage()
                        . " @ " . $e->getFile()
                        . ":" . $e->getLine();
                }

            } finally {
                $wg->done();
            }
        });
    }

    $wg->wait();

    $duration = microtime(true) - $start;

    /*
    |--------------------------------------------------------------------------
    | METRICS
    |--------------------------------------------------------------------------
    */
    sort($queryTime);
    sort($txnTime);

    $qCount = max(count($queryTime), 1);
    $tCount = max(count($txnTime), 1);

    $avgQ = array_sum($queryTime) / $qCount;
    $avgT = array_sum($txnTime) / $tCount;

    $p95Q = $queryTime[(int) floor($qCount * 0.95)] ?? 0;
    $p95T = $txnTime[(int) floor($tCount * 0.95)] ?? 0;

    $qps = round($concurrency / $duration, 2);

    /*
    |--------------------------------------------------------------------------
    | POOL METRICS
    |--------------------------------------------------------------------------
    */
    $allocated = $pool->allocatedCount();
    $idle = $pool->idleCount();

    $saturation = match (true) {
        $allocated < ($poolMax * 0.5) => 'LOW',
        $allocated < $poolMax => 'MID',
        default => 'MAXED',
    };

    $leaks = max(0, $allocated - $idle);

    /*
    |--------------------------------------------------------------------------
    | OUTPUT
    |--------------------------------------------------------------------------
    */
    echo "\n==================== DEVLOG ====================\n\n";

    echo "Concurrency: $concurrency\n";
    echo "Runtime: " . round($duration, 4) . " sec\n";
    echo "QPS: $qps\n";
    echo "Errors: $errors\n\n";

    if ($firstError) {
        echo "FIRST ERROR:\n$firstError\n\n";
    }

    echo "Pool Allocated: $allocated\n";
    echo "Pool Idle: $idle\n";
    echo "Saturation: $saturation\n";
    echo "Leaks: $leaks\n\n";

    echo "Avg Query: " . round($avgQ * 1000, 2) . " ms\n";
    echo "P95 Query: " . round($p95Q * 1000, 2) . " ms\n\n";

    echo "Avg Txn: " . round($avgT * 1000, 2) . " ms\n";
    echo "P95 Txn: " . round($p95T * 1000, 2) . " ms\n\n";

    echo "================================================\n";

    $pool->close();
});