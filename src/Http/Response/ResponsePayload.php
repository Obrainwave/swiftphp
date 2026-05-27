<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Http\Response;

/**
 * The response contracts.
 */
final class ResponsePayload
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = []
    ) {
    }
}