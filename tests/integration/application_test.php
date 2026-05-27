<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Swiftphp\Framework\Core\Application;
use Swiftphp\Framework\Http\Request\Request;
use Swiftphp\Framework\Http\Response\Response;
use Tests\Fixtures\TestMiddleware;

$app = new Application(dirname(__DIR__, 2));

/**
 * Home route check.
 */
$app->get('/', function (Request $request) {

    return Response::ok([
        'message' => 'home',
        'cid' => Coroutine::getCid(),
    ]);
});

/**
 * Basic health check route.
 */
$app->get('/ping', function (Request $request) {

    return Response::ok([
        'message' => 'pong',
        'cid' => Coroutine::getCid(),
    ]);
});

/**
 * Route parameter test.
 */
$app->get('/users/{id:int}', function (Request $request) {

    return Response::ok([
        'user_id' => $request->route('id'),
        'type' => gettype($request->route('id')),
    ]);
});

/**
 * Middleware test.
 */
$app->group(
    '/api',
    [TestMiddleware::class],
    function ($app) {

        $app->get('/status', function (Request $request) {

            return Response::ok([
                'middleware' => $request->swoole()->server['middleware_passed'] ?? false,
            ]);
        });
    }
);

$app->get('/isolation/{id:int}', function (Request $request) {

    Coroutine::sleep(0.05);

    return Response::ok([
        'cid' => Coroutine::getCid(),
        'id' => $request->route('id'),
    ]);
});

$app->get('/benchmark/{id:int}', function (Request $request) {

    $payload = [];

    for ($i = 0; $i < 100; $i++) {
        $payload[] = [
            'id' => $i,
            'request_id' => $request->route('id'),
            'cid' => Coroutine::getCid(),
        ];
    }

    return Response::ok($payload);
});

$app->serve();