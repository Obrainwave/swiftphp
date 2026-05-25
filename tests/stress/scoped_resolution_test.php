<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

use Swiftphp\Framework\Core\Container\Container;
use Swiftphp\Framework\Core\Coroutine\Context;

/**
 * Test service.
 */
final class RequestScopedService
{
    public readonly string $id;

    public function __construct()
    {
        $this->id = uniqid('svc_', true);
    }
}

Coroutine\run(function () {

    $container = new Container();

    /**
     * Register coroutine-scoped binding.
     */
    $container->scoped(
        RequestScopedService::class,
        RequestScopedService::class
    );

    $wg = new WaitGroup();

    $results = [];

    for ($i = 0; $i < 200; $i++) {

        $wg->add();

        Coroutine::create(function () use (
            $container,
            &$results,
            $i,
            $wg
        ) {

            /**
             * Resolve twice inside SAME coroutine.
             *
             * Must return SAME instance.
             */
            $a = $container->make(
                RequestScopedService::class
            );

            $b = $container->make(
                RequestScopedService::class
            );

            /**
             * Verify scoped caching inside coroutine.
             */
            if ($a !== $b) {
                echo "FAILED: same coroutine got different instances\n";
            }

            $results[$i] = spl_object_id($a);

            Coroutine::sleep(0.001);

            /**
             * IMPORTANT:
             * destroy coroutine-local context
             */
            Context::destroy();

            $wg->done();
        });
    }

    $wg->wait();

    /**
     * Every coroutine should receive
     * its own isolated instance.
     */
    $unique = array_unique($results);

    echo 'Resolved: ' . count($results) . PHP_EOL;
    echo 'Unique: ' . count($unique) . PHP_EOL;

    if (count($results) === count($unique)) {
        echo "SCOPED ISOLATION: PASS\n";
    } else {
        echo "SCOPED ISOLATION: FAIL\n";
    }
});