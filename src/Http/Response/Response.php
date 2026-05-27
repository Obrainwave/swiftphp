<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Response;

use Swoole\Http\Response as SwooleResponse;

final class Response
{
    private int $status = 200;

    public function __construct(
        private SwooleResponse $response
    ) {
    }

    public static function ok(array $data, array $headers = []): ResponsePayload
    {
        return new ResponsePayload(200, $data, $headers);
    }

    public static function created(array $data, array $headers = []): ResponsePayload
    {
        return new ResponsePayload(201, $data, $headers);
    }

    public static function notFound(string $message = 'Not Found', array $headers = []): ResponsePayload
    {
        return new ResponsePayload(404, ['error' => $message], $headers);
    }

    public static function unprocessable(array $errors, array $headers = []): ResponsePayload
    {
        return new ResponsePayload(422, ['errors' => $errors], $headers);
    }

    public static function noContent(): ResponsePayload
    {
        return new ResponsePayload(204, []);
    }

    public static function unauthorized(string $message = 'Unauthorized'): ResponsePayload
    {
        return new ResponsePayload(401, ['error' => $message]);
    }

    public static function forbidden(string $message = 'Forbidden'): ResponsePayload
    {
        return new ResponsePayload(403, ['error' => $message]);
    }

    public function stream(callable $writer): void
    {
        $this->response->header('Content-Type', 'text/event-stream');

        $writer($this->response);

        if (!$this->response->isWritable()) {
            return;
        }

        $this->response->end();
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Send structured response payload safely
     */
    public function send(ResponsePayload $payload): void
    {
        if (!$this->response->isWritable()) {
            return;
        }

        // 1. Flush custom headers set by the developer
        foreach ($payload->headers as $key => $value) {
            $this->response->header((string) $key, (string) $value);
        }

        // 2. Enforce default JSON content type if not already overwritten
        if (!isset($payload->headers['Content-Type'])) {
            $this->response->header('Content-Type', 'application/json');
        }

        $this->response->status($payload->status);
        $this->response->end(json_encode($payload->body));
    }

    /**
     * Fluent output fallback
     */
    public function json(array $data): void
    {
        if (!$this->response->isWritable()) {
            return;
        }

        $this->response->status($this->status);
        $this->response->header('Content-Type', 'application/json');
        $this->response->end(json_encode($data));
    }
}