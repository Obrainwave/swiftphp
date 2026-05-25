<?php
// context_isolation_test.php
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swiftphp\Framework\Core\Coroutine\Context;

require __DIR__ . '/../../vendor/autoload.php';

Coroutine\run(function() {
    $results   = [];
    $wg        = new WaitGroup();
    $passes    = 0;
    $failures  = 0;

    for ($i = 0; $i < 500; $i++) {
        $wg->add();
        Coroutine::create(function() use ($i, $wg, &$passes, &$failures) {
            $uniqueVal = "user_{$i}_" . uniqid();
            Context::set('user_id', $uniqueVal);
            Coroutine::sleep(rand(1, 10) / 1000); // random yield
            $retrieved = Context::get('user_id');
            if ($retrieved === $uniqueVal) { $passes++; }
            else { $failures++; echo "LEAK: expected {$uniqueVal}, got {$retrieved}\n"; }
            $wg->done();
        });
    }

    $wg->wait();
    echo "Passes: {$passes} | Failures: {$failures}\n";
    echo ($failures === 0) ? "ISOLATION: PASS\n" : "ISOLATION: FAIL\n";
});
