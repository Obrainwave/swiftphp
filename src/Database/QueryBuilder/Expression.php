<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\QueryBuilder;

/**
 * Representation of a raw SQL fragment that must bypass identifier quoting.
 */
final class Expression
{
    public function __construct(private string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}