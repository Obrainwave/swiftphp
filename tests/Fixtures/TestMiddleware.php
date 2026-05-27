<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Swiftphp\Framework\Http\Middleware\MiddlewareInterface;
use Swiftphp\Framework\Http\Request\Request;

final class TestMiddleware implements MiddlewareInterface
{
    public function handle(
        Request $request,
        callable $next
    ): mixed {

        $request->swoole()->server['middleware_passed'] = true;
        // $request->withAttribute('middleware_passed', true);

        return $next($request);
    }
}