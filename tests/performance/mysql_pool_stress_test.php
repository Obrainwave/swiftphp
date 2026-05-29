<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swiftphp\Framework\Database\Pool\MysqlPool;
use Swiftphp\Framework\Database\DB;

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
}

// Enable full coroutine hooks for underlying network I/O stream splitting
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// Enterprise Database Credentials
$dbConfig = [
    'host'     => '127.0.0.1',
    'port'     => 3305,
    'user'     => 'root',
    'password' => 'secret',
    'database' => 'swiftphp',
];

// Load Testing Curve Config
$concurrencyTiers = [10, 25, 50, 100, 200, 500, 1000];
$report = [];

Coroutine\run(function () use ($dbConfig, $concurrencyTiers, &$report) {

    // Instantiate your optimized MySQL Connection Pool
    $pool = new MysqlPool(
        config: $dbConfig,
        min: 10,
        max: 50,
        timeout: 3.0
    );

    $db = new DB($pool);

    foreach ($concurrencyTiers as $c) {

        echo "\n==============================\n";
        echo "LOAD TEST: {$c} COROUTINES\n";
        echo "==============================\n";

        $wg = new WaitGroup();
        $wg->add($c);

        $latencies = [];
        $errors = 0;

        $start = microtime(true);

        for ($i = 0; $i < $c; $i++) {
            Coroutine::create(function () use ($db, $wg, &$latencies, &$errors) {
                try {
                    $t0 = microtime(true);

                    // Execution against the hardened statement runtime
                    $db->statement('SELECT 1');

                    $t1 = microtime(true);

                    // Co-operative thread-safe append (safely sequence-ordered on loop resumption)
                    $latencies[] = $t1 - $t0;

                } catch (Throwable $e) {
                    $errors++;
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();
        $duration = microtime(true) - $start;

        // -----------------------------
        // METRICS EVALUATION
        // -----------------------------
        $qps        = $c / max($duration, 0.0001);
        $avgLatency = array_sum($latencies) / max(count($latencies), 1);
        $maxLatency = $latencies ? max($latencies) : 0;
        $p95Latency = percentile($latencies, 95);

        $allocated  = $pool->allocatedCount();
        $idle       = $pool->idleCount();

        $report[] = [
            'concurrency' => $c,
            'qps'         => round($qps, 2),
            'avg_ms'      => round($avgLatency * 1000, 2),
            'p95_ms'      => round($p95Latency * 1000, 2),
            'max_ms'      => round($maxLatency * 1000, 2),
            'errors'      => $errors,
            'allocated'   => $allocated,
            'idle'        => $idle,
            'saturation'  => saturationLevel($allocated, 50),
        ];

        // Brief cooldown window between stress bursts to allow connection recycling
        Coroutine::sleep(0.5);
    }

    // Gracefully shut down pool allocations
    $pool->close();

    // -----------------------------
    // DEVLOG VISUALIZATION
    // -----------------------------
    echo "\n\n==================================================================================\n";
    echo "DEVLOG: SWIFTPHP FRAMEWORK DB ENGINE PERFORMANCE\n";
    echo "==================================================================================\n";

    echo str_pad("C", 8)
        . str_pad("QPS", 14)
        . str_pad("Avg(ms)", 12)
        . str_pad("P95(ms)", 12)
        . str_pad("Max(ms)", 12)
        . str_pad("Errors", 10)
        . str_pad("Alloc", 10)
        . str_pad("Idle", 10)
        . str_pad("Sat", 12)
        . "\n";

    echo str_repeat("-", 102) . "\n";

    $fmt = fn($v) => str_pad((string) $v, 14);
    $fmtMetric = fn($v) => str_pad((string) $v, 12);

    foreach ($report as $r) {
        echo str_pad((string) $r['concurrency'], 8)
            . $fmt($r['qps'])
            . $fmtMetric($r['avg_ms'])
            . $fmtMetric($r['p95_ms'])
            . $fmtMetric($r['max_ms'])
            . str_pad((string) $r['errors'], 10)
            . str_pad((string) $r['allocated'], 10)
            . str_pad((string) $r['idle'], 10)
            . str_pad((string) $r['saturation'], 12)
            . "\n";
    }
    echo "======================================================================================================\n";
});

// -----------------------------
// Core Engine Helpers
// -----------------------------
function percentile(array $values, float $p): float
{
    if (!$values) {
        return 0.0;
    }

    sort($values);
    $index = (int) ceil(($p / 100) * count($values)) - 1;

    return $values[max(0, $index)] ?? 0.0;
}

function saturationLevel(int $allocated, int $max): string
{
    $ratio = $max > 0 ? ($allocated / $max) : 0;

    return match (true) {
        $ratio < 0.3 => 'LOW',
        $ratio < 0.7 => 'MID',
        $ratio < 1.0 => 'HIGH',
        default      => 'MAXED'
    };
}