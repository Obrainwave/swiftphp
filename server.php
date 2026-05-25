<?php
// server.php
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

use Swiftphp\Framework\Core\Bootstrap\ConfigureRuntime;
use Swiftphp\Framework\Core\Coroutine\Context;

require __DIR__ . '/vendor/autoload.php';

/**
 * Boot coroutine runtime configuration
 * (hooks + lifecycle behavior)
 */
ConfigureRuntime::boot();

$server = new Server('0.0.0.0', 8080);

$server->set([
    'worker_num'       => swoole_cpu_num() * 2,
    'max_coroutine'    => 100000,
    'enable_coroutine' => true,
]);

/**
 * HTTP request lifecycle entry point
 */
$server->on('request', function (Request $req, Response $res) {

    $cid  = Coroutine::getCid();
    $path = $req->server['request_uri'];

    try {

        $res->header('Content-Type', 'application/json');

        $res->end(json_encode([
            'cid'  => $cid,
            'path' => $path,
        ]));

    } finally {

        /**
         * IMPORTANT:
         * Ensure full request isolation cleanup
         *
         * Without this, coroutine memory leaks and
         * cross-request context bleeding will occur.
         */
        Context::destroy();
    }
});

$server->start();