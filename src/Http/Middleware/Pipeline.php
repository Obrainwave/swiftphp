<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Middleware;

use RuntimeException;
use Swiftphp\Framework\Core\Container\Container;

final class Pipeline
{
    /**
     * Build and return the executable middleware onion chain.
     */
    // public function handle(
    //     array $middleware,
    //     callable $destination,
    //     Container $container
    // ): callable {
    //     return array_reduce(
    //         array_reverse($middleware),
    //         function (callable $next, string $middlewareClass) use ($container) {
    //             return function ($request) use ($next, $middlewareClass, $container) {

    //                 $instance = $container->make($middlewareClass);

    //                 if (!$instance instanceof MiddlewareInterface) {
    //                     throw new RuntimeException(
    //                         "SwiftPHP Middleware Error: [{$middlewareClass}] must implement MiddlewareInterface."
    //                     );
    //                 }

    //                 return $instance->handle($request, $next);
    //             };
    //         },
    //         $destination
    //     );
    // }

    public function handle(
        array $middleware,
        callable $destination,
        Container $container
    ): callable {
        $pipeline = $destination;

        /**
         * Manual reverse iteration instead of array_reduce for clarity.
         * Each closure captures the previous pipeline stage by value,
         * building the middleware onion from outermost to innermost.
         */
        for ($i = count($middleware) - 1; $i >= 0; $i--) {
            $middlewareClass = $middleware[$i];

            $pipeline = function ($request) use ($pipeline, $middlewareClass, $container) {
                $instance = $container->make($middlewareClass);

                if (!$instance instanceof MiddlewareInterface) {
                    throw new RuntimeException(
                        "SwiftPHP Middleware Error: [{$middlewareClass}] must implement MiddlewareInterface."
                    );
                }

                return $instance->handle($request, $pipeline);
            };
        }

        return $pipeline;
    }
}