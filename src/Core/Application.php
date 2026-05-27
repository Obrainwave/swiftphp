<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core;

use Closure;
use Throwable;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swiftphp\Framework\Core\Bootstrap\ConfigureRuntime;
use Swiftphp\Framework\Core\Container\Container;
use Swiftphp\Framework\Core\Coroutine\Context;
use Swiftphp\Framework\Http\Middleware\Pipeline;
use Swiftphp\Framework\Http\Request\Request;
use Swiftphp\Framework\Http\Response\Response;
use Swiftphp\Framework\Http\Response\ResponsePayload;
use Swiftphp\Framework\Http\Router\Router;

final class Application
{
    private Router $router;
    private Container $container;
    private ?Server $server = null;
    private array $middleware = [];

    /**
     * Environment debug toggle to prevent information leakage in production.
     */
    private bool $debug = true;

    public function __construct(
        private readonly string $basePath
    ) {
        $this->router = new Router();
        $this->container = new Container();

        $this->container->singleton(Container::class, fn() => $this->container);
        $this->container->singleton(self::class, fn() => $this);

        $this->container->scoped(
            Request::class,
            fn() => Context::get(Request::class)
        );

        $this->container->scoped(
            Response::class,
            fn() => Context::get(Response::class)
        );
    }

    public function middleware(string $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, Closure|array $handler): void
    {
        $this->router->get($path, $handler);
    }
    public function post(string $path, Closure|array $handler): void
    {
        $this->router->post($path, $handler);
    }
    public function put(string $path, Closure|array $handler): void
    {
        $this->router->put($path, $handler);
    }
    public function patch(string $path, Closure|array $handler): void
    {
        $this->router->patch($path, $handler);
    }
    public function delete(string $path, Closure|array $handler): void
    {
        $this->router->delete($path, $handler);
    }

    public function group(
        string $prefix,
        array $middleware,
        Closure $callback
    ): void {
        $this->router->group($prefix, $middleware, $callback);
    }

    /**
     * Mutate environment debug settings.
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function serve(string $host = '0.0.0.0', int $port = 8080): void
    {
        ConfigureRuntime::boot();
        $this->server = new Server($host, $port);

        $this->server->set([
            'worker_num' => swoole_cpu_num() * 2,
            'enable_coroutine' => true,
            'max_coroutine' => 100000,
        ]);
        echo "INFO Server running on [http://{$host}:{$port}]\n\n";
        echo "Press Ctrl+C to stop the server.\n\n";

        $this->server->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void {
            $request = new Request($swooleRequest);
            $response = new Response($swooleResponse);
            try {
                Context::set(Request::class, $request);
                Context::set(Response::class, $response);

                $route = $this->router->match($request->method(), $request->path());

                if ($route === null) {
                    $response->send(Response::notFound('Route not found'));
                    return;
                }

                $request->setRouteParams($route['params'] ?? []);

                $destination = function (Request $request) use ($route) {
                    return $this->runHandler($route['handler'], $request);
                };

                // Pure merge preserving chronological middleware execution semantics
                $middlewareStack = array_merge($this->middleware, $route['middleware'] ?? []);

                $pipeline = new Pipeline();
                $runner = $pipeline->handle($middlewareStack, $destination, $this->container);

                $result = $runner($request);

                if ($result instanceof ResponsePayload) {
                    $response->send($result);

                    return;
                }

                if ($result === null) {
                    return;
                }

                throw new RuntimeException('SwiftPHP Route Error: Invalid return type.');

            } catch (Throwable $e) {
                // Response Double-Send Protection
                if ($swooleResponse->isWritable()) {

                    // Enterprise Exception Information Leak Guard
                    $body = ['error' => 'Internal Server Error'];

                    if ($this->debug) {
                        $body['message'] = $e->getMessage();
                        $body['trace'] = $e->getTrace();
                        $body['file'] = $e->getFile();
                        $body['line'] = $e->getLine();
                    }

                    $response->send(new ResponsePayload(500, $body));
                }
            } finally {
                // Unified Context lifecycle teardown mapping back to CID engine rules
                Context::destroy();
            }
        });

        $this->server->start();
    }

    private function runHandler(Closure|array $handler, Request $request): mixed
    {
        if ($handler instanceof Closure) {
            return $handler($request);
        }

        [$controllerClass, $method] = $handler;
        $controller = $this->container->make($controllerClass);

        if (!method_exists($controller, $method)) {
            throw new RuntimeException(
                sprintf('SwiftPHP Controller Error: Method [%s::%s] does not exist.', $controllerClass, $method)
            );
        }

        return $controller->{$method}($request);
    }
}