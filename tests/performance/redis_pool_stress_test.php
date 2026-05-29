<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swiftphp\Framework\Database\Pool\RedisPool;

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
}

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$dbConfig = [
    'host'     => '127.0.0.1',
    'port'     => 6377,
    'user'     => 'root',
    // 'password' => 'secret',
    'database' => 'swiftphp',
];

$concurrencyTiers = [10, 25, 50, 100, 200, 500, 1000];
$report = [];

Coroutine\run(function () use ($dbConfig, $concurrencyTiers, &$report) {

    $pool = new RedisPool(
        config: $dbConfig,
        min: 10,
        max: 50,
        timeout: 3.0
    );

    foreach ($concurrencyTiers as $c) {

        echo "\n==============================\n";
        echo "LOAD TEST: {$c} COROUTINES\n";
        echo "==============================\n";

        $wg = new WaitGroup();
        $wg->add($c);

        $latencies = [];
        $errors    = 0;
        $start     = microtime(true);

        for ($i = 0; $i < $c; $i++) {

            $key = "swiftphp:test:{$i}";

            Coroutine::create(function () use (
                $pool, $wg, $key, $i,
                &$latencies, &$errors
            ) {
                try {
                    $t0   = microtime(true);
                    $conn = $pool->acquire();

                    try {
                        // SET + GET — covers both write and read paths
                        $conn->set($key, "value_{$i}", 30);
                        $value = $conn->get($key);

                        if ($value !== "value_{$i}") {
                            throw new \RuntimeException(
                                "Data integrity failure: expected value_{$i}, got {$value}"
                            );
                        }

                        // Cleanup
                        $conn->del($key);

                    } finally {
                        $pool->release($conn);
                    }

                    $latencies[] = microtime(true) - $t0;

                } catch (\Throwable $e) {
                    $errors++;
                    echo "ERROR [{$e->getMessage()}]\n";
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();
        $duration = microtime(true) - $start;

        $qps           = $c / max($duration, 0.0001);
        $avgLatency    = $latencies ? array_sum($latencies) / count($latencies) : 0;
        $p95Latency    = percentile($latencies, 95);
        $maxLatency    = $latencies ? max($latencies) : 0;

        $report[] = [
            'concurrency' => $c,
            'qps'         => round($qps, 2),
            'avg_ms'      => round($avgLatency * 1000, 3),
            'p95_ms'      => round($p95Latency * 1000, 3),
            'max_ms'      => round($maxLatency * 1000, 3),
            'errors'      => $errors,
            'allocated'   => $pool->allocatedCount(),
            'idle'        => $pool->idleCount(),
        ];

        Coroutine::sleep(0.3);
    }

    $pool->close();

    // ── DEVLOG OUTPUT ─────────────────────────────────
    echo "\n\n==============================\n";
    echo "DEVLOG: REDIS POOL RESULTS\n";
    echo "==============================\n";

    echo str_pad("C",       6)
       . str_pad("QPS",    12)
       . str_pad("Avg(ms)", 12)
       . str_pad("P95(ms)", 12)
       . str_pad("Max(ms)", 12)
       . str_pad("Errors",  10)
       . str_pad("Alloc",   10)
       . str_pad("Idle",    10)
       . "\n";

    echo str_repeat("-", 84) . "\n";

    foreach ($report as $r) {
        echo str_pad((string) $r['concurrency'], 6)
           . str_pad((string) $r['qps'],         12)
           . str_pad((string) $r['avg_ms'],      12)
           . str_pad((string) $r['p95_ms'],      12)
           . str_pad((string) $r['max_ms'],      12)
           . str_pad((string) $r['errors'],      10)
           . str_pad((string) $r['allocated'],   10)
           . str_pad((string) $r['idle'],        10)
           . "\n";
    }
    echo "====================================================================================\n";
});

function percentile(array $values, float $p): float
{
    if (!$values) return 0.0;
    sort($values);
    $index = (int) ceil(($p / 100) * count($values)) - 1;
    return $values[max(0, $index)] ?? 0.0;
}