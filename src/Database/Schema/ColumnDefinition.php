<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Schema;

final class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public mixed $length = null,
        public bool $nullable = true,
        public mixed $default = null,
        public bool $autoIncrement = false,
        public bool $primary = false,
    ) {}

    // ----------------------------
    // Factory methods
    // ----------------------------

    public static function uuid(string $name): self
    {
        return new self($name, 'uuid');
    }

    public static function string(string $name, int $length = 255): self
    {
        return new self($name, 'string', $length);
    }

    public static function text(string $name): self
    {
        return new self($name, 'text');
    }

    public static function integer(string $name): self
    {
        return new self($name, 'integer');
    }

    public static function boolean(string $name): self
    {
        return new self($name, 'boolean');
    }

    public static function timestamp(string $name): self
    {
        return new self($name, 'timestamp');
    }

    public static function bigIncrements(string $name): self
    {
        return new self($name, 'bigint', null, false, null, true, true);
    }

    // ----------------------------
    // modifiers
    // ----------------------------

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        return $this;
    }
}