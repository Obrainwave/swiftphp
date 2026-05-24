<?php
declare(strict_types=1);

$server = new Swoole\Http\Server('0.0.0.0', 8080);
$server->set([
    'worker_num'    => swoole_cpu_num() * 2,
    'max_coroutine' => 100000,
    'enable_coroutine' => true,
]);

$server->on('request', function(\Swoole\Http\Request $req, \Swoole\Http\Response $res) {
    $cid  = \Swoole\Coroutine::getCid();
    $path = $req->server['request_uri'];
    if ($path === '/ping') {
        $res->end('pong');
        return;
    }
    if ($path === '/slow') {
        \Swoole\Coroutine::sleep(1.0); // yields — does NOT block event loop
    }

    $res->header('Content-Type', 'application/json');
    $res->end(json_encode(['cid' => $cid, 'path' => $path]));
});

$server->start();
