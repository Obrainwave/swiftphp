<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Request;

use Swoole\Http\Request as SwooleRequest;

final class Request
{
    /**
     * Route parameters extracted by the router.
     */
    private array $routeParams = [];

    /**
     * Cached JSON payload to prevent redundant parsing overhead.
     */
    private ?array $cachedJson = null;

    public function __construct(
        private readonly SwooleRequest $swoole
    ) {
    }

    public function method(): string
    {
        return strtoupper($this->swoole->server['request_method'] ?? 'GET');
    }

    public function path(): string
    {
        return $this->swoole->server['request_uri'] ?? '/';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->swoole->get[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->swoole->get ?? [];
    }

    /**
     * Form-urlencoded or multipart POST data.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->swoole->post[$key] ?? $default;
    }

    /**
     * All POST body fields.
     */
    public function allPost(): array
    {
        return $this->swoole->post ?? [];
    }

    public function json(): array
    {
        if ($this->cachedJson !== null) {
            return $this->cachedJson;
        }

        $raw = $this->rawBody();

        if ($raw === '') {
            return $this->cachedJson = [];
        }

        $decoded = json_decode($raw, true);

        return $this->cachedJson = is_array($decoded) ? $decoded : [];
    }

/**
     * Safely grabs raw content, protecting against Swoole's boolean false return.
     */
    public function rawBody(): string
    {
        $content = $this->swoole->rawContent();
        
        return $content === false ? '' : $content;
    }

    /**
     * Unified input accessor.
     *
     * Resolution priority:
     *
     * 1. Route params
     * 2. POST fields
     * 3. JSON body
     * 4. Query string
     *
     * This mirrors real-world API expectations where:
     * route identity > body > query.
     */
    public function input(
        string $key,
        mixed $default = null
    ): mixed {

        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }

        if (isset($this->swoole->post[$key])) {
            return $this->swoole->post[$key];
        }

        $json = $this->json();

        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->swoole->get[$key] ?? $default;
    }

    /**
     * Unified merged input payload.
     *
     * Merge precedence:
     *
     * query < json < post < route
     */
    public function all(): array
    {
        return array_merge(
            $this->allQuery(),
            $this->json(),
            $this->allPost(),
            $this->routeParams
        );
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->swoole->header[strtolower($key)] ?? $default;
    }

    /**
     * Store route parameters from router match().
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Retrieve a route parameter.
     */
    public function route(
        string $key,
        mixed $default = null
    ): mixed {

        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Retrieve all route parameters.
     */
    public function allRouteParams(): array
    {
        return $this->routeParams;
    }


    public function swoole(): SwooleRequest
    {
        return $this->swoole;
    }
}