<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\QueryBuilder;

use Swiftphp\Framework\Database\Pool\ConnectionPool;
use Swoole\Coroutine;
use PDO;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;

/**
 * Immutable Fluent SQL Query Builder for SwiftPHP.
 */
class Builder
{
    private const VALID_OPERATORS = [
        '=',
        '!=',
        '<>',
        '<',
        '>',
        '<=',
        '>=',
        'LIKE',
        'NOT LIKE',
    ];

    public const TRANSACTION_CONTEXT_KEY = '__swiftphp_active_transaction_connection__';

    private ConnectionPool $pool;
    private string|Expression $table = '';
    /** @var array<int, string|Expression> */
    private array $columns = ['*'];
    private array $wheres = [];
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    public function __construct(ConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    public function table(string|Expression $table): static
    {
        $clone = clone $this;
        $clone->table = $table;
        return $clone;
    }

    public function select(string|Expression ...$columns): static
    {
        $clone = clone $this;
        $clone->columns = $columns ?: ['*'];
        return $clone;
    }

    public function where(string|Expression $column, mixed $operator, mixed $value = null): static
    {
        return $this->addWhere($column, $operator, $value, 'AND');
    }

    public function orWhere(string|Expression $column, mixed $operator, mixed $value = null): static
    {
        return $this->addWhere($column, $operator, $value, 'OR');
    }

    public function whereIn(string|Expression $column, array $values): static
    {
        if (empty($values)) {
            throw new InvalidArgumentException('whereIn() requires a non-empty values array.');
        }

        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => array_values($values),
            'boolean' => 'AND',
        ];

        return $clone;
    }

    public function whereNull(string|Expression $column): static
    {
        $clone = clone $this;
        $clone->wheres[] = ['type' => 'null', 'column' => $column, 'boolean' => 'AND'];
        return $clone;
    }

    public function whereNotNull(string|Expression $column): static
    {
        $clone = clone $this;
        $clone->wheres[] = ['type' => 'notNull', 'column' => $column, 'boolean' => 'AND'];
        return $clone;
    }

    public function orderBy(string|Expression $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Order direction must be 'ASC' or 'DESC'.");
        }

        $clone = clone $this;
        $clone->orders[] = ['column' => $column, 'direction' => $direction];
        return $clone;
    }

    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Query LIMIT value cannot be negative.');
        }

        $clone = clone $this;
        $clone->limitValue = $limit;
        return $clone;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Query OFFSET value cannot be negative.');
        }

        $clone = clone $this;
        $clone->offsetValue = $offset;
        return $clone;
    }

    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        return $this->async(function (PDO $conn) use ($sql, $bindings): array {
            $stmt = $this->executeStatement($conn, $sql, $bindings);
            return $stmt->fetchAll();
        });
    }

    public function first(): ?array
    {
        [$sql, $bindings] = $this->limit(1)->compileSelect();
        return $this->async(function (PDO $conn) use ($sql, $bindings): ?array {
            $stmt = $this->executeStatement($conn, $sql, $bindings);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        });
    }

    public function exists(): bool
    {
        [$sql, $bindings] = $this->select(new Expression('1'))->limit(1)->compileSelect();
        return $this->async(function (PDO $conn) use ($sql, $bindings): bool {
            $stmt = $this->executeStatement($conn, $sql, $bindings);
            return $stmt->fetch() !== false;
        });
    }

    public function count(string|Expression $column = '*'): int
    {
        $target = $this->quoteIdentifier($column);
        $expression = new Expression("COUNT({$target}) AS __swiftphp_aggregate__");

        $clone = $this->select($expression);
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;

        $row = $clone->first();
        return $row ? (int) $row['__swiftphp_aggregate__'] : 0;
    }

    public function insert(array $data): int
    {
        $this->guardTable();
        if (!array_is_list($data)) {
            $data = [$data];
        }

        if (empty($data) || empty($data[0])) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $columns = array_keys($data[0]);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteIdentifier($this->table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_fill(0, count($data), $rowPlaceholder))
        );

        $bindings = [];
        foreach ($data as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        return $this->async(function (PDO $conn) use ($sql, $bindings): int {
            $stmt = $this->executeStatement($conn, $sql, $bindings);
            return $stmt->rowCount();
        });
    }

    public function insertGetId(array $data): int
    {
        $this->guardTable();
        if (empty($data)) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($this->table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $bindings = array_values($data);
        return $this->async(function (PDO $conn) use ($sql, $bindings): int {
            $this->executeStatement($conn, $sql, $bindings);
            return (int) $conn->lastInsertId();
        });
    }

    public function update(array $data): int
    {
        $this->guardTable();
        if (empty($data)) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }

        $setClauses = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $setClauses[] = $this->quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s',
            $this->quoteIdentifier($this->table),
            implode(', ', $setClauses)
        );

        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }

        return $this->async(function (PDO $conn) use ($sql, $bindings): int {
            $stmt = $this->executeStatement($conn, $sql, $bindings);
            return $stmt->rowCount();
        });
    }

    public function delete(): int
    {
        $this->guardTable();
        $sql = sprintf('DELETE FROM %s', $this->quoteIdentifier($this->table));

        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return $this->async(function (PDO $conn) use ($sql, $whereBindings): int {
            $stmt = $this->executeStatement($conn, $sql, $whereBindings);
            return $stmt->rowCount();
        });
    }

    public function toSql(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        return ['sql' => $sql, 'bindings' => $bindings];
    }

    private function async(callable $work): mixed
    {
        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $context = Coroutine::getContext($cid);
            if ($context && isset($context[self::TRANSACTION_CONTEXT_KEY])) {
                return $work($context[self::TRANSACTION_CONTEXT_KEY]);
            }
        }

        $conn = $this->pool->acquire();
        try {
            return $work($conn);
        } finally {
            $this->pool->release($conn);
        }
    }

    private function compileSelect(): array
    {
        $this->guardTable();
        $bindings = [];
        $columnsSql = (count($this->columns) === 1 && $this->columns[0] === '*')
            ? '*'
            : implode(', ', array_map([$this, 'quoteIdentifier'], $this->columns));

        $sql = sprintf('SELECT %s FROM %s', $columnsSql, $this->quoteIdentifier($this->table));

        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }

        if (!empty($this->orders)) {
            $clauses = array_map(
                fn(array $o) => $this->quoteIdentifier($o['column']) . ' ' . $o['direction'],
                $this->orders
            );
            $sql .= ' ORDER BY ' . implode(', ', $clauses);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ?';
            $bindings[] = $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ?';
            $bindings[] = $this->offsetValue;
        }

        return [$sql, $bindings];
    }

    private function compileWheres(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $fragments = [];
        $bindings = [];

        foreach ($this->wheres as $i => $w) {
            $isExpressionValue = isset($w['value']) && $w['value'] instanceof Expression;

            $clause = match ($w['type']) {
                'basic' => $isExpressionValue
                ? $this->quoteIdentifier($w['column']) . ' ' . $w['operator'] . ' ' . $w['value']->getValue()
                : $this->quoteIdentifier($w['column']) . ' ' . $w['operator'] . ' ?',
                'in' => $this->quoteIdentifier($w['column'])
                . ' IN (' . implode(', ', array_fill(0, count($w['values']), '?')) . ')',
                'null' => $this->quoteIdentifier($w['column']) . ' IS NULL',
                'notNull' => $this->quoteIdentifier($w['column']) . ' IS NOT NULL',
                default => throw new RuntimeException("Unknown where type: {$w['type']}"),
            };

            match ($w['type']) {
                'basic' => $isExpressionValue ? null : $bindings[] = $w['value'],
                'in' => array_push($bindings, ...$w['values']),
                default => null,
            };

            $fragments[] = ($i === 0) ? $clause : ($w['boolean'] . ' ' . $clause);
        }

        return [implode(' ', $fragments), $bindings];
    }

    private function executeStatement(PDO $conn, string $sql, array $bindings): PDOStatement
    {
        if (empty($bindings)) {
            return $conn->query($sql);
        }

        $stmt = $conn->prepare($sql);

        foreach ($bindings as $index => $value) {
            $paramIndex = $index + 1;
            $type = match (gettype($value)) {
                'integer' => PDO::PARAM_INT,
                'boolean' => PDO::PARAM_BOOL,
                'NULL' => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            if ($type === PDO::PARAM_BOOL) {
                $value = $value ? 1 : 0;
                $type = PDO::PARAM_INT;
            }

            $stmt->bindValue($paramIndex, $value, $type);
        }

        $stmt->execute();
        return $stmt;
    }

    private function addWhere(string|Expression $column, mixed $operator, mixed $value, string $boolean): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $upper = strtoupper((string) $operator);
        if (!in_array($upper, self::VALID_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid WHERE operator: '{$operator}'.");
        }

        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $upper,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $clone;
    }

    private function quoteIdentifier(string|Expression $identifier): string
    {
        if ($identifier instanceof Expression) {
            return $identifier->getValue();
        }

        if ($identifier === '*') {
            return $identifier;
        }

        if (preg_match('/\s+as\s+/i', $identifier)) {
            $parts = preg_split('/\s+as\s+/i', $identifier);
            return $this->quoteIdentifier($parts[0]) . ' AS ' . $this->quoteIdentifier($parts[1]);
        }

        if (str_contains($identifier, '.')) {
            return implode('.', array_map(
                fn(string $part) => $part === '*' ? '*' : '`' . str_replace('`', '``', $part) . '`',
                explode('.', $identifier)
            ));
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function guardTable(): void
    {
        $tableStr = $this->table instanceof Expression ? $this->table->getValue() : $this->table;
        if ($tableStr === '') {
            throw new RuntimeException('No table specified. Call table() before executing a query.');
        }
    }
}