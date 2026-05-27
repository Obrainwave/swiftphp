<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Router;

use Closure;

final class Router
{
    private array $routes = [];

    /**
     * Active route group stack.
     *
     * Used to support nested groups:
     *
     */
    private array $groupStack = [];

    public function get(string $path, Closure|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, Closure|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, Closure|array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, Closure|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function patch(string $path, Closure|array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function options(string $path, Closure|array $handler): void
    {
        $this->add('OPTIONS', $path, $handler);
    }

    public function head(string $path, Closure|array $handler): void
    {
        $this->add('HEAD', $path, $handler);
    }

    /**
     * Register grouped routes.
     *
     */
    public function group(
        string $prefix,
        array $middleware,
        Closure $callback
    ): void {

        $this->groupStack[] = [
            'prefix' => $this->normalizePath($prefix),
            'middleware' => $middleware,
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, Closure|array $handler): void
    {
        $prefix = '';
        $middleware = [];

        /**
         * Merge nested group prefixes + middleware.
         */
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];

            $middleware = array_merge(
                $middleware,
                $group['middleware']
            );
        }

        $fullPath = $this->normalizePath(
            $prefix . '/' . ltrim($path, '/')
        );

        $this->routes[$method][] = [
            'path' => $this->compile($fullPath),
            'raw' => $fullPath,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function match(string $method, string $uri): ?array
    {
        // Protect the engine from messy client URLs
        $normalizedUri = $this->normalizePath($uri);

        foreach ($this->routes[$method] ?? [] as $route) {

            if (preg_match($route['path']['regex'], $normalizedUri, $matches)) {

                $params = [];

                foreach ($route['path']['params'] as $key => $type) {
                    $params[$key] = $matches[$key] ?? null;

                    if ($type === 'int') {
                        $params[$key] = (int) $params[$key];
                    }
                }

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware']
                ];
            }
        }

        return null;
    }

    /**
     * Compile:
     * /users/{id:int}
     * regex + param map
     */
    private function compile(string $path): array
    {
        $params = [];

        $regex = preg_replace_callback(
            '#\{(\w+)(?::(int))?\}#',
            function ($matches) use (&$params) {

                $name = $matches[1];
                $type = $matches[2] ?? 'string';

                $params[$name] = $type;

                // Dynamically assign the regex token based on the type definition
                $pattern = $type === 'int' ? '\d+' : '[^/]+';

                return "(?P<{$name}>{$pattern})";
            },
            $path
        );

        return [
            'regex' => "#^{$regex}$#",
            'params' => $params
        ];
    }

    /**
     * Normalize route paths.
     *
     * Ensures:
     * - leading slash exists
     * - duplicate slashes removed
     * - trailing slash removed (except root)
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        $path = preg_replace('#/+#', '/', $path);

        return $path !== '/'
            ? rtrim($path, '/')
            : '/';
    }
}