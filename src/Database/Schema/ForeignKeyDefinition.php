<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Schema;

final class ForeignKeyDefinition
{
    public function __construct(
        public string $column,
        public ?string $references = null,
        public ?string $on = null,
        public string $onDelete = 'CASCADE',
        public string $onUpdate = 'CASCADE',
    ) {}

    public function references(string $column): self
    {
        $this->references = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->on = $table;
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        $this->onDelete = 'CASCADE';
        return $this;
    }

    public function restrictOnDelete(): self
    {
        $this->onDelete = 'RESTRICT';
        return $this;
    }

    public function toSql(): array
    {
        return [
            'column' => $this->column,
            'references' => $this->references,
            'on' => $this->on,
            'onDelete' => $this->onDelete,
            'onUpdate' => $this->onUpdate,
        ];
    }
}