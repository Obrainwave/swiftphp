<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database;

use Swiftphp\Framework\Database\Pool\ConnectionPool;
use Swiftphp\Framework\Database\QueryBuilder\Builder;
use Swiftphp\Framework\Database\Exceptions\QueryException;
use Swoole\Coroutine;
use PDO;
use PDOException;
use PDOStatement;
use Closure;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Hardened Database façade for SwiftPHP.
 */
final class DBManager
{
    private const TRANSACTION_DEPTH_KEY = '__swiftphp_transaction_depth__';
    private const POST_COMMIT_HOOKS_KEY = '__swiftphp_post_commit_hooks__';

    private const FATAL_CONN_ERRNOS = [2006, 2013];
    private const RETRYABLE_DB_ERRNOS = [1213, 1205];

    private const DDL_PATTERN = '/^\s*(ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i';

    private const MAX_DEADLOCK_RETRIES = 3;
    private const RETRY_BACKOFF_MS = 50;
    private const WATCHDOG_THRESHOLD_S = 2.0;

    // [UPDATED] Kept active pool reference but added array for named pool management
    private ConnectionPool $pool;

    /** @var array<string, ConnectionPool> */
    private array $pools = []; // [NEW]

    private string $connectionName; // [NEW] Tracks current connection context

    /**
     * [UPDATED] Constructor now accepts a single pool or an array of pools for multi-database setups.
     * 
     * @param ConnectionPool|array<string, ConnectionPool> $pools
     */
    public function __construct(ConnectionPool|array $pools, string $defaultConnection = 'default')
    {
        if ($pools instanceof ConnectionPool) {
            $pools = [$defaultConnection => $pools];
        }

        if (empty($pools[$defaultConnection])) {
            throw new InvalidArgumentException("Default connection [{$defaultConnection}] not found in provided pools.");
        }

        $this->pools = $pools;
        $this->pool = $pools[$defaultConnection];
        $this->connectionName = $defaultConnection;
    }

    /**
     * [NEW] Switches the connection context.
     * Returns a cloned instance to maintain coroutine safety and state immutability.
     */
    public function connection(?string $name = null): self
    {
        $name ??= 'default';

        if (!isset($this->pools[$name])) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        // Return same instance if already using the requested connection
        if ($this->connectionName === $name) {
            return $this;
        }

        $instance = clone $this;
        $instance->pool = $this->pools[$name];
        $instance->connectionName = $name;

        return $instance;
    }

    /**
     * Allows dynamic registration of new connections at runtime.
     */
    public function addConnection(string $name, ConnectionPool $pool): void
    {
        $this->pools[$name] = $pool;
    }

    // Helper methods for connection-aware coroutine context keys to prevent cross-connection transaction leakage.
    private function getTxContextKey(): string
    {
        return Builder::TRANSACTION_CONTEXT_KEY . "_{$this->connectionName}";
    }

    private function getTxDepthKey(): string
    {
        return self::TRANSACTION_DEPTH_KEY . "_{$this->connectionName}";
    }

    private function getTxHooksKey(): string
    {
        return self::POST_COMMIT_HOOKS_KEY . "_{$this->connectionName}";
    }

    public function table(string $table): Builder
    {
        return (new Builder($this->pool, $this->connectionName))->table($table);
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $this->guardAgainstImplicitCommit($sql);

        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $context = Coroutine::getContext($cid);
            $txKey = $this->getTxContextKey();

            if (isset($context[$txKey])) {
                return $this->executeRawStatement($context[$txKey], $sql, $bindings);
            }
        }

        $conn = $this->pool->acquire();
        try {
            return $this->executeRawStatement($conn, $sql, $bindings);
        } finally {
            $this->pool->release($conn);
        }
    }

    public function transaction(Closure $callback): mixed
    {
        $cid = Coroutine::getCid();
        if ($cid < 1) {
            throw new RuntimeException('DB::transaction() must be called inside a Swoole coroutine context.');
        }

        $context = Coroutine::getContext($cid);
        $depthKey = $this->getTxDepthKey(); // [UPDATED] Uses dynamic key
        $depth = $context[$depthKey] ?? 0;

        if ($depth === 0) {
            $attempts = 0;

            while (true) {
                try {
                    $attempts++;
                    return $this->executeRootTransaction($context, $callback);
                } catch (Throwable $e) {
                    $errno = $this->extractDriverErrorCode($e);

                    if ($attempts < self::MAX_DEADLOCK_RETRIES && in_array($errno, self::RETRYABLE_DB_ERRNOS, true)) {
                        Coroutine::sleep(self::RETRY_BACKOFF_MS / 1000);
                        continue;
                    }
                    throw $e;
                }
            }
        }

        return $this->executeNestedTransaction($context, $callback, $depth);
    }

    public function afterCommit(Closure $hook): void
    {
        $cid = Coroutine::getCid();
        if ($cid < 1) {
            $hook();
            return;
        }

        $context = Coroutine::getContext($cid);
        $txKey = $this->getTxContextKey(); // [UPDATED] Uses dynamic key

        if (!isset($context[$txKey])) {
            $hook();
            return;
        }

        $hooksKey = $this->getTxHooksKey(); // [UPDATED] Uses dynamic key
        $hooks = $context[$hooksKey] ?? [];
        $hooks[] = $hook;
        $context[$hooksKey] = $hooks;
    }

    public function inTransaction(): bool
    {
        $cid = Coroutine::getCid();
        // [UPDATED] Dynamic key lookup
        return $cid > 0 && isset(Coroutine::getContext($cid)[$this->getTxContextKey()]);
    }

    public function guardAgainstImplicitCommit(string $sql): void
    {
        if (!$this->inTransaction()) {
            return;
        }

        if (preg_match(self::DDL_PATTERN, $sql)) {
            throw new RuntimeException(sprintf(
                'DDL statement [%s...] would trigger an implicit COMMIT inside an active '
                . 'transaction. Execute structural changes outside of DB::transaction().',
                substr(trim($sql), 0, 60)
            ));
        }
    }

    private function executeRootTransaction(mixed $context, Closure $callback): mixed
    {
        $conn = $this->pool->acquire();
        $fatalErrorOccurred = false;
        $startTime = microtime(true);

        // [UPDATED] Resolve dynamic keys once for performance
        $txKey = $this->getTxContextKey();
        $depthKey = $this->getTxDepthKey();
        $hooksKey = $this->getTxHooksKey();

        try {
            $context[$txKey] = $conn;
            $context[$depthKey] = 1;
            $context[$hooksKey] = [];

            $conn->beginTransaction();
            $returnValue = $callback();
            $conn->commit();

            $hooks = $context[$hooksKey] ?? [];
            foreach ($hooks as $hook) {
                try {
                    $hook();
                } catch (Throwable) {
                    // Prevent hook isolation pollution from compromising state
                }
            }

            return $returnValue;
        } catch (Throwable $e) {
            $errno = $this->extractDriverErrorCode($e);
            if (in_array($errno, self::FATAL_CONN_ERRNOS, true)) {
                $fatalErrorOccurred = true;
            }

            $this->safeRollback($conn);
            throw $e;
        } finally {
            $duration = microtime(true) - $startTime;
            if ($duration > self::WATCHDOG_THRESHOLD_S) {
                error_log(sprintf('WARNING: Long running transaction held connection for %.2f seconds.', $duration));
            }

            // [UPDATED] Unset connection-specific keys
            unset($context[$txKey]);
            unset($context[$depthKey]);
            unset($context[$hooksKey]);

            if ($fatalErrorOccurred) {
                $this->pool->discard($conn);
            } else {
                $this->pool->release($conn);
            }
        }
    }

    private function executeNestedTransaction(mixed $context, Closure $callback, int $depth): mixed
    {
        $txKey = $this->getTxContextKey();
        $depthKey = $this->getTxDepthKey();

        // [UPDATED] Use dynamic keys
        $conn = $context[$txKey];
        $savepoint = "swiftphp_sp_{$depth}";

        $conn->exec("SAVEPOINT {$savepoint}");
        $context[$depthKey] = $depth + 1;

        try {
            $returnValue = $callback();
            $conn->exec("RELEASE SAVEPOINT {$savepoint}");
            return $returnValue;
        } catch (Throwable $e) {
            $this->safeRollbackTo($conn, $savepoint);
            throw $e;
        } finally {
            $context[$depthKey] = $depth; // [UPDATED]
        }
    }

    private function safeRollback(PDO $conn): void
    {
        try {
            $conn->rollBack();
        } catch (Throwable) {
        }
    }

    private function safeRollbackTo(PDO $conn, string $savepoint): void
    {
        try {
            $conn->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        } catch (Throwable) {
        }
    }

    private function extractDriverErrorCode(Throwable $e): int
    {
        if ($e instanceof QueryException) {
            return $e->getDriverErrorCode();
        }

        if ($e instanceof PDOException && isset($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        return 0;
    }

    private function executeRawStatement(PDO $conn, string $sql, array $bindings): PDOStatement
    {
        try {
            if (empty($bindings)) {
                return $conn->query($sql);
            }

            $stmt = $conn->prepare($sql);

            foreach ($bindings as $index => $value) {
                $paramIndex = is_int($index) ? $index + 1 : $index;

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
        } catch (PDOException $e) {
            throw new QueryException(
                message: $e->getMessage(),
                sql: $sql,
                bindings: $bindings,
                errorInfo: $e->errorInfo ?? [],
                previous: $e,
                connection: 'default'
            );
        }
    }
}