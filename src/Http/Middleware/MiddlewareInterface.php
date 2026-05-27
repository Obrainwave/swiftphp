<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Middleware;

use Swiftphp\Framework\Http\Request\Request;

interface MiddlewareInterface
{
    /**
     * Handle an inbound HTTP request.
     */
    public function handle(Request $request, callable $next): mixed;
}