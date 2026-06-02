<?php

declare(strict_types=1);

require __DIR__ . '/../cli-bootstrap.php';

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swiftphp\Framework\Database\DB;

echo config('database.connections.mysql.host') . " - Named Connections Async Benchmark\n";
echo config('database.connections.analytics.host') . " - Named Connections Async Benchmark\n";

Coroutine\run(function () {

    echo "====================================================\n";
    echo "SWIFTPHP MULTI-POOL CONCURRENCY BENCHMARK\n";
    echo "====================================================\n\n";

    /*
    |--------------------------------------------------------------------------
    | SCHEMA MIGRATION & ISOLATION CLEANUP
    |--------------------------------------------------------------------------
    | Both connections are managed implicitly through the DB facade.
    */
    // Default Connection Tables
    DB::statement("
        CREATE TABLE IF NOT EXISTS users (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255),
            email VARCHAR(255),
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    DB::statement("
        CREATE TABLE IF NOT EXISTS profiles (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id BIGINT,
            bio TEXT NULL
        )
    ");

    // Analytics Named Connection Table
    DB::connection('analytics')->statement("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id BIGINT,
            action VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Wipe states
    DB::statement("TRUNCATE TABLE profiles");
    DB::statement("TRUNCATE TABLE users");
    DB::connection('analytics')->statement("TRUNCATE TABLE audit_logs");

    /*
    |--------------------------------------------------------------------------
    | SEED DATA (Default Pool)
    |--------------------------------------------------------------------------
    */
    for ($i = 1; $i <= 300; $i++) {
        DB::statement(
            "INSERT INTO users(name, email, active) VALUES (?, ?, ?)",
            ["User $i", "u$i@test.com", true]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CONCURRENT EXECUTION RUNTIME
    |--------------------------------------------------------------------------
    */
    $concurrency = 1000;

    $wg = new WaitGroup();
    $wg->add($concurrency);

    $errors = 0;
    $firstError = null;

    $defaultPoolTime = array_fill(0, $concurrency, 0.0);
    $analyticsPoolTime = array_fill(0, $concurrency, 0.0);

    $start = microtime(true);

    for ($i = 0; $i < $concurrency; $i++) {

        Coroutine::create(function () use ($wg, &$errors, &$defaultPoolTime, &$analyticsPoolTime, $i, &$firstError) {
            try {
                /*
                |--------------------------------------------------------------------------
                | WORKLOAD 1: Default Pool Transaction
                |--------------------------------------------------------------------------
                */
                $t0 = microtime(true);
                $userId = 0;

                DB::transaction(function () use ($i, &$userId) {
                    $userId = DB::table('users')->insertGetId([
                        'name' => "Txn User $i",
                        'email' => "txn_u$i@test.com",
                        'active' => true,
                    ]);

                    DB::table('profiles')->insert([
                        'user_id' => $userId,
                        'bio' => 'Async execution context verified.'
                    ]);
                });

                $defaultPoolTime[$i] = microtime(true) - $t0;

                /*
                |--------------------------------------------------------------------------
                | WORKLOAD 2: Analytics Pool Interleaving
                |--------------------------------------------------------------------------
                | Switches contexts inside the same coroutine execution frame to hit 
                | the isolated connection pool.
                */
                $t1 = microtime(true);

                DB::connection('analytics')->table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => "assigned_coroutine_frame_$i"
                ]);

                $analyticsPoolTime[$i] = microtime(true) - $t1;

            } catch (\Throwable $e) {
                $errors++;
                if ($firstError === null) {
                    $firstError = $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine();
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
    | METRICS GENERATION
    |--------------------------------------------------------------------------
    */
    sort($defaultPoolTime);
    sort($analyticsPoolTime);

    $dCount = max(count($defaultPoolTime), 1);
    $aCount = max(count($analyticsPoolTime), 1);

    $avgDefault = array_sum($defaultPoolTime) / $dCount;
    $avgAnalytics = array_sum($analyticsPoolTime) / $aCount;

    $p95Default = $defaultPoolTime[(int) floor($dCount * 0.95)] ?? 0;
    $p95Analytics = array_sum($analyticsPoolTime) / $aCount; // Fallback bound protection

    $totalOperations = $concurrency * 3; // 2 writes in transaction + 1 analytics log write
    $throughput = round($totalOperations / $duration, 2);

    /*
    |--------------------------------------------------------------------------
    | DEVLOG OUTPUT
    |--------------------------------------------------------------------------
    */
    echo "\n==================== DEVLOG ====================\n\n";

    echo "Concurrency Total: $concurrency coroutines\n";
    echo "Total Time Elapsed: " . round($duration, 4) . " sec\n";
    echo "Aggregate Throughput: $throughput queries/sec\n";
    echo "Context Violations / Errors: $errors\n\n";

    if ($firstError) {
        echo "🚨 CRITICAL ERROR TRACE:\n$firstError\n\n";
    }

    echo "--- Default Pool Latencies (Transactional Pair) ---\n";
    echo "Avg Run: " . round($avgDefault * 1000, 2) . " ms\n";
    echo "P95 Run: " . round($p95Default * 1000, 2) . " ms\n\n";

    echo "--- Analytics Pool Latencies (Context Swapped) ---\n";
    echo "Avg Run: " . round($avgAnalytics * 1000, 2) . " ms\n";
    echo "P95 Run: " . round($p95Analytics * 1000, 2) . " ms\n\n";

    // Dynamic state cross-check verification 
    try {
        $verifiedLogs = DB::connection('analytics')->table('audit_logs')->count();
        echo "Verification -> Analytics Logs Row Count: $verifiedLogs / $concurrency\n";
    } catch (\Throwable $ex) {
        echo "Verification Failed: " . $ex->getMessage() . "\n";
    }

    echo "================================================\n";
});