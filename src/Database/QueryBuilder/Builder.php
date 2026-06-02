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
    private string $connectionName;
    private string|Expression|\Closure|self $table = '';
    private ?string $tableAlias = null; // Track subquery table aliases
    
    /** @var array<int, string|Expression> */
    private array $columns = ['*'];
    private array $wheres = [];
    
    /** @var array<int, array{type: string, table: string|Expression, first: string|Expression, operator: string, second: string|Expression}> */
    private array $joins = []; // Track applied table joins
    
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private Grammar $grammar;

    public function __construct(ConnectionPool $pool, string $connectionName = 'default')
    {
        $this->pool = $pool;
        $this->connectionName = $connectionName;
        $this->grammar = new Grammar($pool->getDialect());
    }

    /**
     * Supports string tables, Expression, Closure subqueries, or nested Builder instances.
     */
    public function table(string|Expression|\Closure|self $table, ?string $alias = null): static
    {
        $clone = clone $this;
        $clone->table = $table;
        if ($alias !== null) {
            $clone->tableAlias = $alias;
        }
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

    /**
     * Accepting arrays, Closures, or nested Builder instances for subquery targeting.
     */
    public function whereIn(string|Expression $column, array|\Closure|self $values): static
    {
        if (is_array($values) && empty($values)) {
            throw new InvalidArgumentException('whereIn() requires a non-empty values array.');
        }

        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => is_array($values) ? array_values($values) : $values,
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

    /**
     * NEW: Add custom INNER JOIN layer
     */
    public function join(string|Expression $table, string|Expression $first, string $operator, string|Expression $second, string $type = 'INNER'): static
    {
        $clone = clone $this;
        $clone->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $clone;
    }

    public function leftJoin(string|Expression $table, string|Expression $first, string $operator, string|Expression $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string|Expression $table, string|Expression $first, string $operator, string|Expression $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
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
        return (int) ($this->aggregate('COUNT', $column) ?? 0);
    }

    public function sum(string|Expression $column): mixed
    {
        $result = $this->aggregate('SUM', $column);
        return $result !== null ? (str_contains((string) $result, '.') ? (float) $result : (int) $result) : null;
    }

    public function max(string|Expression $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string|Expression $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Generic engine abstraction layer driving aggregate operations uniformly
     */
    private function aggregate(string $function, string|Expression $column): mixed
    {
        $target = $this->quoteIdentifier($column);
        $expression = new Expression("{$function}({$target}) AS __swiftphp_aggregate__");

        $clone = $this->select($expression);
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;

        $row = $clone->first();
        return $row ? $row['__swiftphp_aggregate__'] : null;
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
            
            // [UPDATED] Match the exact dynamic key pattern generated by the DBManager
            $txKey = self::TRANSACTION_CONTEXT_KEY . "_{$this->connectionName}";
            
            if ($context && isset($context[$txKey])) {
                return $work($context[$txKey]);
            }
        }

        $conn = $this->pool->acquire();
        try {
            return $work($conn);
        } finally {
            $this->pool->release($conn);
        }
    }

    /**
     * Orchestrates table subqueries, join definitions, and custom criteria.
     */
    private function compileSelect(): array
    {
        $this->guardTable();
        $bindings = [];
        
        // Evaluate base tables / subquery states dynamically
        $tableSql = $this->compileTable($bindings);

        $columnsSql = (count($this->columns) === 1 && $this->columns[0] === '*')
            ? '*'
            : implode(', ', array_map([$this, 'quoteIdentifier'], $this->columns));

        $sql = sprintf('SELECT %s FROM %s', $columnsSql, $tableSql);

        // Compile Applied Table Joins
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= sprintf(
                    ' %s JOIN %s ON %s %s %s',
                    $join['type'],
                    $this->quoteIdentifier($join['table']),
                    $this->quoteIdentifier($join['first']),
                    $join['operator'],
                    $this->quoteIdentifier($join['second'])
                );
            }
        }

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

    /**
     * Processes subquery table targets safely and appends structural parameters.
     */
    private function compileTable(array &$bindings): string
    {
        if ($this->table instanceof \Closure || $this->table instanceof self) {
            $subQuery = $this->table;
            if ($subQuery instanceof \Closure) {
                $subQuery = new self($this->pool);
                ($this->table)($subQuery);
            }

            [$sql, $subBindings] = $subQuery->compileSelect();
            array_push($bindings, ...$subBindings);
            
            $alias = $this->tableAlias ?? 'sub__';
            return "({$sql}) AS " . $this->quoteIdentifier($alias);
        }

        $tableStr = $this->quoteIdentifier($this->table);
        if ($this->tableAlias !== null) {
            $tableStr .= ' AS ' . $this->quoteIdentifier($this->tableAlias);
        }
        return $tableStr;
    }

    /**
     * Recursively extracts parameters from nested query conditions.
     */
    private function compileWheres(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $fragments = [];
        $bindings = [];

        foreach ($this->wheres as $i => $w) {
            $isExpressionValue = isset($w['value']) && $w['value'] instanceof Expression;
            
            // Identify recursive basic or IN constraints
            $isSubQueryValue = isset($w['value']) && ($w['value'] instanceof \Closure || $w['value'] instanceof self);
            $isSubQueryIn = isset($w['values']) && ($w['values'] instanceof \Closure || $w['values'] instanceof self);

            $clause = '';
            if ($w['type'] === 'basic') {
                if ($isExpressionValue) {
                    $clause = $this->quoteIdentifier($w['column']) . ' ' . $w['operator'] . ' ' . $w['value']->getValue();
                } elseif ($isSubQueryValue) {
                    $subQuery = $w['value'];
                    if ($subQuery instanceof \Closure) {
                        $subQuery = new self($this->pool);
                        ($w['value'])($subQuery);
                    }
                    [$subSql, $subBindings] = $subQuery->compileSelect();
                    $clause = $this->quoteIdentifier($w['column']) . ' ' . $w['operator'] . ' (' . $subSql . ')';
                    $w['subBindings'] = $subBindings;
                } else {
                    $clause = $this->quoteIdentifier($w['column']) . ' ' . $w['operator'] . ' ?';
                }
            } elseif ($w['type'] === 'in') {
                if ($isSubQueryIn) {
                    $subQuery = $w['values'];
                    if ($subQuery instanceof \Closure) {
                        $subQuery = new self($this->pool);
                        ($w['values'])($subQuery);
                    }
                    [$subSql, $subBindings] = $subQuery->compileSelect();
                    $clause = $this->quoteIdentifier($w['column']) . ' IN (' . $subSql . ')';
                    $w['subBindings'] = $subBindings;
                } else {
                    $clause = $this->quoteIdentifier($w['column']) . ' IN (' . implode(', ', array_fill(0, count($w['values']), '?')) . ')';
                }
            } else {
                $clause = match ($w['type']) {
                    'null' => $this->quoteIdentifier($w['column']) . ' IS NULL',
                    'notNull' => $this->quoteIdentifier($w['column']) . ' IS NOT NULL',
                    default => throw new RuntimeException("Unknown where type: {$w['type']}"),
                };
            }

            // Sequentially merge execution scopes
            if ($w['type'] === 'basic') {
                if ($isSubQueryValue) {
                    array_push($bindings, ...$w['subBindings']);
                } elseif (!$isExpressionValue) {
                    $bindings[] = $w['value'];
                }
            } elseif ($w['type'] === 'in') {
                if ($isSubQueryIn) {
                    array_push($bindings, ...$w['subBindings']);
                } else {
                    array_push($bindings, ...$w['values']);
                }
            }

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

    /**
     * Bypass plain string check verification when closures/builders run as subqueries
     */
    private function guardTable(): void
    {
        if ($this->table instanceof \Closure || $this->table instanceof self) {
            return;
        }
        $tableStr = $this->table instanceof Expression ? $this->table->getValue() : $this->table;
        if ($tableStr === '') {
            throw new RuntimeException('No table specified. Call table() before executing a query.');
        }
    }
}