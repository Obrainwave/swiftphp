<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\QueryBuilder;

use InvalidArgumentException;

/**
 * Universal SQL Grammar Compiler for SwiftPHP.
 *
 * Compiles the Builder's query AST into dialect-correct SQL for MySQL and PostgreSQL.
 * Uses a method-dispatch architecture for WHERE clauses, making the compiler
 * trivially extensible without modifying existing compilation logic.
 *
 * BINDING ORDER CONTRACT:
 * The Builder MUST aggregate its parameter bindings in the exact chronological
 * sequence that this compiler strings placeholders together:
 *
 *   1. Join bindings
 *   2. Where bindings
 *   3. Having bindings
 *   4. Order bindings (if raw expressions use parameters)
 *
 * Violating this order produces silent parameter misalignment — the most
 * dangerous class of SQL bugs because queries execute without errors
 * but return incorrect data.
 *
 * Builder getter contract expected:
 *   getColumns(): array
 *   getFrom(): string
 *   getJoins(): array
 *   getWheres(): array
 *   getGroups(): array
 *   getHavings(): array
 *   getOrders(): array
 *   getLimit(): ?int
 *   getOffset(): ?int
 *   getDistinct(): bool
 *   getAggregate(): ?array   ['function' => string, 'column' => string]
 *   getLock(): string|bool|null
 */
class Grammar
{
    private string $dialect;
    private bool $foldIdentifierCase;
    private const SUPPORTED_DIALECTS = ['mysql', 'pgsql'];

    /**
     * Maps where clause types to their dedicated compiler methods.
     * Adding a new where type requires only a new constant entry and a matching method.
     */
    private const WHERE_COMPILERS = [
        'Basic' => 'compileWhereBasic',
        'In' => 'compileWhereIn',
        'NotIn' => 'compileWhereNotIn',
        'Null' => 'compileWhereNull',
        'NotNull' => 'compileWhereNotNull',
        'Between' => 'compileWhereBetween',
        'NotBetween' => 'compileWhereNotBetween',
        'Column' => 'compileWhereColumn',
        'Exists' => 'compileWhereExists',
        'NotExists' => 'compileWhereNotExists',
        'Raw' => 'compileWhereRaw',
    ];

    /**
     * @throws InvalidArgumentException If the dialect is not supported.
     */
    public function __construct(string $dialect = 'mysql', bool $foldIdentifierCase = true)
    {
        $this->dialect = strtolower($dialect);
        $this->foldIdentifierCase = $foldIdentifierCase;
        if (!in_array($this->dialect, self::SUPPORTED_DIALECTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported database dialect [%s]. Supported: %s.',
                $this->dialect,
                implode(', ', self::SUPPORTED_DIALECTS)
            ));
        }
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    // =========================================================================
    //  Identifier Quoting
    // =========================================================================

    /**
     * Wraps a value in dialect-appropriate identifier quotes.
     * Handles wildcards, aliases ("users as u"), dot notation ("public.users.id"),
     * and raw Expression objects that must bypass quoting entirely.
     */
    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }

        if ($value === '*') {
            return $value;
        }

        // Handle aliases: "users as u" → `users` AS `u`
        if (stripos($value, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $value, 2);
            return $this->wrap(trim($segments[0])) . ' AS ' . $this->wrapIdentifier(trim($segments[1]));
        }

        // Handle dot notation: "public.users.id" → "public"."users"."id"
        if (str_contains($value, '.')) {
            return implode('.', array_map(
                fn(string $segment) => $this->wrapIdentifier(trim($segment)),
                explode('.', $value)
            ));
        }

        return $this->wrapIdentifier($value);
    }

    /**
     * Escapes a single identifier segment using dialect-specific quote characters.
     * MySQL uses backticks; PostgreSQL uses double quotes (SQL standard).
     */
    protected function wrapIdentifier(string $value): string
    {
        if ($value === '*') {
            return $value;
        }
        return match ($this->dialect) {
            'pgsql' => '"' . str_replace('"', '""', $this->foldIdentifierCase ? strtolower($value) : $value) . '"',
            'mysql' => '`' . str_replace('`', '``', $value) . '`',
        };
    }

    /**
     * Wraps every element in the array through wrap().
     */
    public function wrapArray(array $values): array
    {
        return array_map(fn(string|Expression $v) => $this->wrap($v), $values);
    }

    /**
     * Wraps and implodes an array of columns into a comma-separated string.
     */
    public function columnize(array $columns): string
    {
        return implode(', ', $this->wrapArray($columns));
    }

    /**
     * Generates a comma-separated string of positional placeholders.
     */
    public function parameterize(int $count): string
    {
        return implode(', ', array_fill(0, max($count, 0), '?'));
    }

    // =========================================================================
    //  Statement Compilers
    // =========================================================================

    /**
     * Compiles a full SELECT statement from the Builder's AST.
     */
    public function compileSelect(Builder $query): string
    {
        $aggregate = $query->getAggregate();
        $distinct = $query->getDistinct();

        $components = [];

        $components[] = $aggregate !== null
            ? $this->compileAggregate($aggregate, $distinct)
            : $this->compileColumns($query->getColumns(), $distinct);

        $components[] = $this->compileFrom($query->getFrom());
        $components[] = $this->compileJoins($query->getJoins());
        $components[] = $this->compileWheres($query->getWheres());
        $components[] = $this->compileGroups($query->getGroups());
        $components[] = $this->compileHavings($query->getHavings());
        $components[] = $this->compileOrders($query->getOrders());
        $components[] = $this->compileLimitOffset($query->getLimit(), $query->getOffset());
        $components[] = $this->compileLock($query->getLock());

        return trim(implode(' ', array_filter($components, fn(string $s) => $s !== '')));
    }

    /**
     * Compiles an existence check: SELECT EXISTS(SELECT ...) AS "exists".
     */
    public function compileExists(Builder $query): string
    {
        $innerSql = $this->compileSelect($query);

        return sprintf(
            'SELECT EXISTS(%s) AS %s',
            $innerSql,
            $this->wrapIdentifier('exists')
        );
    }

    /**
     * Compiles an INSERT statement.
     * Supports both single-row and multi-row batch inserts.
     *
     * Single row: $values = ['name' => 'John', 'email' => '...']
     * Multi-row:  $values = [['name' => 'John', ...], ['name' => 'Jane', ...]]
     */
    public function compileInsert(Builder $query, array $values): string
    {
        $table = $this->wrap($query->getFrom());

        if (empty($values)) {
            return match ($this->dialect) {
                'pgsql' => "INSERT INTO {$table} DEFAULT VALUES",
                'mysql' => "INSERT INTO {$table} () VALUES ()",
            };
        }

        // Normalize to multi-row format for uniform compilation
        $rows = $this->isAssociative($values) ? [$values] : $values;

        $columns = $this->columnize(array_keys($rows[0]));
        $rowCount = count($rows[0]);

        $rowPlaceholders = array_map(
            fn() => '(' . $this->parameterize($rowCount) . ')',
            $rows
        );

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            $columns,
            implode(', ', $rowPlaceholders)
        );
    }

    /**
     * Compiles an INSERT that expects a returned auto-increment ID.
     * PostgreSQL uses RETURNING; MySQL relies on PDO::lastInsertId().
     */
    public function compileInsertGetId(Builder $query, array $values, string $sequence = 'id'): string
    {
        $sql = $this->compileInsert($query, $values);

        if ($this->dialect === 'pgsql') {
            $sql .= ' RETURNING ' . $this->wrapIdentifier($sequence);
        }

        return $sql;
    }

    /**
     * Compiles an INSERT ... IGNORE / ON CONFLICT DO NOTHING statement.
     * Silently discards rows that violate unique constraints.
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        if ($this->dialect === 'mysql') {
            // MySQL: INSERT IGNORE INTO ...
            $sql = $this->compileInsert($query, $values);
            return preg_replace('/^INSERT\b/i', 'INSERT IGNORE', $sql);
        }

        // PostgreSQL: INSERT INTO ... ON CONFLICT DO NOTHING
        return $this->compileInsert($query, $values) . ' ON CONFLICT DO NOTHING';
    }

    /**
     * Compiles an UPSERT statement.
     *
     * MySQL:      INSERT INTO ... ON DUPLICATE KEY UPDATE col = VALUES(col)
     * PostgreSQL: INSERT INTO ... ON CONFLICT (unique_cols) DO UPDATE SET col = EXCLUDED.col
     *
     * @param array  $values       The row(s) to insert.
     * @param array  $uniqueBy     Columns that form the unique constraint (required for PostgreSQL).
     * @param array  $updateColumns Columns to update on conflict. If empty, updates all non-unique columns.
     */

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $updateColumns = []): string
    {
        $rows = $this->isAssociative($values) ? [$values] : $values;
        $allColumns = array_keys($rows[0]);
        if (empty($updateColumns)) {
            $updateColumns = array_values(array_diff($allColumns, $uniqueBy));
        }
        if (empty($updateColumns)) {
            return $this->compileInsertOrIgnore($query, $values);
        }
        $sql = $this->compileInsert($query, $values);
        if ($this->dialect === 'mysql') {
            // Modern MySQL 8.0.20+ alias syntax avoids deprecated VALUES() function
            $alias = 'swiftphp_upsert_row';
            $updates = array_map(
                fn(string $col) => $this->wrap($col) . ' = ' . $alias . '.' . $this->wrap($col),
                $updateColumns
            );
            return $sql . ' AS ' . $alias
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }
        // PostgreSQL: EXCLUDED pseudo-table references the conflicting row
        $conflictColumns = $this->columnize($uniqueBy);
        $updates = array_map(
            fn(string $col) => $this->wrap($col) . ' = EXCLUDED.' . $this->wrap($col),
            $updateColumns
        );
        return sprintf(
            '%s ON CONFLICT (%s) DO UPDATE SET %s',
            $sql,
            $conflictColumns,
            implode(', ', $updates)
        );
    }

    /**
     * Compiles an UPDATE statement.
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        $table = $this->wrap($query->getFrom());

        $assignments = array_map(
            fn(string $column) => $this->wrap($column) . ' = ?',
            array_keys($values)
        );

        $sql = sprintf('UPDATE %s SET %s', $table, implode(', ', $assignments));

        $wheres = $query->getWheres();
        if (!empty($wheres)) {
            $sql .= ' ' . $this->compileWheres($wheres);
        }

        return $sql;
    }

    /**
     * Compiles a DELETE statement.
     */
    public function compileDelete(Builder $query): string
    {
        $sql = 'DELETE FROM ' . $this->wrap($query->getFrom());

        $wheres = $query->getWheres();
        if (!empty($wheres)) {
            $sql .= ' ' . $this->compileWheres($wheres);
        }

        return $sql;
    }

    /**
     * Compiles a TRUNCATE statement.
     * PostgreSQL needs explicit RESTART IDENTITY CASCADE; MySQL uses TRUNCATE TABLE.
     */
    public function compileTruncate(Builder $query): string
    {
        $table = $this->wrap($query->getFrom());

        return match ($this->dialect) {
            'pgsql' => "TRUNCATE {$table} RESTART IDENTITY CASCADE",
            'mysql' => "TRUNCATE TABLE {$table}",
        };
    }

    // =========================================================================
    //  SELECT Component Compilers
    // =========================================================================

    protected function compileAggregate(array $aggregate, bool $distinct): string
    {
        $function = strtoupper($aggregate['function']);
        $column = $this->wrap($aggregate['column']);

        // DISTINCT inside aggregates: COUNT(DISTINCT col)
        if ($distinct && $aggregate['column'] !== '*') {
            $column = 'DISTINCT ' . $column;
        }

        return sprintf('SELECT %s(%s) AS %s', $function, $column, $this->wrapIdentifier('aggregate'));
    }

    protected function compileColumns(?array $columns, bool $distinct = false): string
    {
        $select = $distinct ? 'SELECT DISTINCT' : 'SELECT';

        if (empty($columns)) {
            return "{$select} *";
        }

        return "{$select} " . $this->columnize($columns);
    }

    protected function compileFrom(?string $from): string
    {
        if (empty($from)) {
            return '';
        }

        return 'FROM ' . $this->wrap($from);
    }

    protected function compileJoins(?array $joins): string
    {
        if (empty($joins)) {
            return '';
        }

        $compiled = [];
        foreach ($joins as $join) {
            $compiled[] = sprintf(
                '%s JOIN %s ON %s %s %s',
                strtoupper($join['type']),
                $this->wrap($join['table']),
                $this->wrap($join['first']),
                $join['operator'],
                $this->wrap($join['second'])
            );
        }

        return implode(' ', $compiled);
    }

    protected function compileGroups(?array $groups): string
    {
        if (empty($groups)) {
            return '';
        }

        return 'GROUP BY ' . $this->columnize($groups);
    }

    protected function compileHavings(?array $havings): string
    {
        if (empty($havings)) {
            return '';
        }

        $compiled = [];
        foreach ($havings as $index => $having) {
            $prefix = $index === 0 ? '' : strtoupper($having['boolean']) . ' ';
            $compiled[] = $prefix . $this->wrap($having['column']) . ' ' . $having['operator'] . ' ?';
        }

        return 'HAVING ' . implode(' ', $compiled);
    }

    protected function compileOrders(?array $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        $compiled = [];
        foreach ($orders as $order) {
            $type = $order['type'] ?? 'Column';

            if ($type === 'Random') {
                $compiled[] = match ($this->dialect) {
                    'pgsql' => 'RANDOM()',
                    'mysql' => 'RAND()',
                };
            } elseif ($type === 'Raw') {
                $compiled[] = $order['sql'];
            } else {
                $compiled[] = $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
            }
        }

        return 'ORDER BY ' . implode(', ', $compiled);
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= 'LIMIT ' . $limit;
        }

        if ($offset !== null) {
            $sql .= ($sql !== '' ? ' ' : '') . 'OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * Compiles a pessimistic lock clause.
     *
     * @param string|bool|null $lock  true or 'update' → FOR UPDATE;
     *                                'share' → FOR SHARE (PgSQL) / LOCK IN SHARE MODE (MySQL);
     *                                false/null → no lock.
     */
    protected function compileLock(string|bool|null $lock): string
    {
        if ($lock === null || $lock === false) {
            return '';
        }

        if ($lock === true || $lock === 'update') {
            return 'FOR UPDATE';
        }

        if ($lock === 'share') {
            return match ($this->dialect) {
                'pgsql' => 'FOR SHARE',
                'mysql' => 'LOCK IN SHARE MODE',
            };
        }

        // Allow raw lock strings for advanced use cases
        return $lock;
    }

    // =========================================================================
    //  WHERE Clause Compilers (Method-Dispatch Architecture)
    // =========================================================================

    /**
     * Compiles the full WHERE clause by dispatching each where entry
     * to its dedicated compiler method via the WHERE_COMPILERS map.
     *
     * @throws InvalidArgumentException If a where type has no registered compiler.
     */
    protected function compileWheres(?array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $compiled = [];
        foreach ($wheres as $index => $where) {
            $type = $where['type'];
            $method = self::WHERE_COMPILERS[$type] ?? null;

            if ($method === null) {
                throw new InvalidArgumentException("Unknown where clause type [{$type}].");
            }

            $prefix = $index === 0 ? '' : strtoupper($where['boolean']) . ' ';
            $compiled[] = $prefix . $this->{$method}($where);
        }

        return 'WHERE ' . implode(' ', $compiled);
    }

    protected function compileWhereBasic(array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?';
    }

    protected function compileWhereIn(array $where): string
    {
        return $this->wrap($where['column']) . ' IN (' . $this->parameterize(count($where['values'])) . ')';
    }

    protected function compileWhereNotIn(array $where): string
    {
        return $this->wrap($where['column']) . ' NOT IN (' . $this->parameterize(count($where['values'])) . ')';
    }

    protected function compileWhereNull(array $where): string
    {
        return $this->wrap($where['column']) . ' IS NULL';
    }

    protected function compileWhereNotNull(array $where): string
    {
        return $this->wrap($where['column']) . ' IS NOT NULL';
    }

    protected function compileWhereBetween(array $where): string
    {
        return $this->wrap($where['column']) . ' BETWEEN ? AND ?';
    }

    protected function compileWhereNotBetween(array $where): string
    {
        return $this->wrap($where['column']) . ' NOT BETWEEN ? AND ?';
    }

    /**
     * Column-to-column comparison (no parameter binding needed).
     */
    protected function compileWhereColumn(array $where): string
    {
        return $this->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->wrap($where['second']);
    }

    protected function compileWhereExists(array $where): string
    {
        return 'EXISTS (' . $where['sql'] . ')';
    }

    protected function compileWhereNotExists(array $where): string
    {
        return 'NOT EXISTS (' . $where['sql'] . ')';
    }

    protected function compileWhereRaw(array $where): string
    {
        return $where['sql'];
    }

    // =========================================================================
    //  Internal Utilities
    // =========================================================================

    /**
     * Determines if an array is associative (string keys) vs sequential (integer keys).
     * Used to distinguish single-row inserts from multi-row batch inserts.
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return !array_is_list($array);
    }
}