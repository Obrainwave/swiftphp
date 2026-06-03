<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Schema;

/**
 * Schema Blueprint DSL
 * Describes table structure and yields fluidly chainable column definitions.
 */
final class Blueprint
{
    public string $table;

    /** @var ColumnDefinition[] */
    public array $columns = [];

    /** @var array<int, array> */
    public array $indexes = [];

    /** @var ForeignKeyDefinition[] */
    public array $foreignKeys = [];

    public ?string $engine = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    private function addColumn(ColumnDefinition $column): ColumnDefinition
    {
        $this->columns[] = $column;
        return $column;
    }

    // ------------------------------------------------------------
    // Columns (Now returning ColumnDefinition instead of self)
    // ------------------------------------------------------------

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($name);
    }

    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::uuid($name));
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::string($name, $length));
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::text($name));
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::integer($name));
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::boolean($name));
    }

    public function bigIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn(ColumnDefinition::bigIncrements($name));
    }

    public function timestamps(): self
    {
        $this->addColumn(ColumnDefinition::timestamp('created_at'))->nullable(false);
        $this->addColumn(ColumnDefinition::timestamp('updated_at'))->nullable(false);
        return $this;
    }

    // ------------------------------------------------------------
    // Indexes & Constraints
    // ------------------------------------------------------------

    public function index(array|string $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'index',
            'columns' => (array) $columns,
            'name' => $name,
        ];
        return $this;
    }

    public function unique(array|string $columns, ?string $name = null): self
    {
        $this->indexes[] = [
            'type' => 'unique',
            'columns' => (array) $columns,
            'name' => $name,
        ];
        return $this;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }
}