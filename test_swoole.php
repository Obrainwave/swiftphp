<?php
// test_swoole.php — run this before anything else
\Swoole\Coroutine\run(function() {
    $cid = \Swoole\Coroutine::getCid();
    echo "Coroutine ID: {$cid}" . PHP_EOL;
    echo "Swoole version: " . SWOOLE_VERSION . PHP_EOL;
});
