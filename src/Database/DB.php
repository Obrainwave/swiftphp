<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database;

use Swiftphp\Framework\Database\Pool\ConnectionPool;
use Swiftphp\Framework\Database\QueryBuilder\Builder;
use Swoole\Coroutine;
use PDO;
use PDOException;
use PDOStatement;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Hardened Database façade for SwiftPHP.
 */
class DB
{
    private const TRANSACTION_DEPTH_KEY = '__swiftphp_transaction_depth__';
    private const POST_COMMIT_HOOKS_KEY = '__swiftphp_post_commit_hooks__';

    private const FATAL_CONN_ERRNOS = [2006, 2013];
    private const RETRYABLE_DB_ERRNOS = [1213, 1205];

    private const DDL_PATTERN = '/^\s*(ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i';

    private const MAX_DEADLOCK_RETRIES = 3;
    private const RETRY_BACKOFF_MS = 50;
    private const WATCHDOG_THRESHOLD_S = 2.0;

    private ConnectionPool $pool;

    public function __construct(ConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    public function table(string $table): Builder
    {
        return (new Builder($this->pool))->table($table);
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $this->guardAgainstImplicitCommit($sql);

        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $context = Coroutine::getContext($cid);
            if (isset($context[Builder::TRANSACTION_CONTEXT_KEY])) {
                return $this->executeRawStatement($context[Builder::TRANSACTION_CONTEXT_KEY], $sql, $bindings);
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
        $depth = $context[self::TRANSACTION_DEPTH_KEY] ?? 0;

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
        if (!isset($context[Builder::TRANSACTION_CONTEXT_KEY])) {
            $hook();
            return;
        }

        $hooks = $context[self::POST_COMMIT_HOOKS_KEY] ?? [];
        $hooks[] = $hook;
        $context[self::POST_COMMIT_HOOKS_KEY] = $hooks;
    }

    public function inTransaction(): bool
    {
        $cid = Coroutine::getCid();
        return $cid > 0 && isset(Coroutine::getContext($cid)[Builder::TRANSACTION_CONTEXT_KEY]);
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

        try {
            $context[Builder::TRANSACTION_CONTEXT_KEY] = $conn;
            $context[self::TRANSACTION_DEPTH_KEY] = 1;
            $context[self::POST_COMMIT_HOOKS_KEY] = [];

            $conn->beginTransaction();
            $returnValue = $callback();
            $conn->commit();

            $hooks = $context[self::POST_COMMIT_HOOKS_KEY] ?? [];
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

            unset($context[Builder::TRANSACTION_CONTEXT_KEY]);
            unset($context[self::TRANSACTION_DEPTH_KEY]);
            unset($context[self::POST_COMMIT_HOOKS_KEY]);

            if ($fatalErrorOccurred) {
                $this->pool->discard($conn);
            } else {
                $this->pool->release($conn);
            }
        }
    }

    private function executeNestedTransaction(mixed $context, Closure $callback, int $depth): mixed
    {
        $conn = $context[Builder::TRANSACTION_CONTEXT_KEY];
        $savepoint = "swiftphp_sp_{$depth}";

        $conn->exec("SAVEPOINT {$savepoint}");
        $context[self::TRANSACTION_DEPTH_KEY] = $depth + 1;

        try {
            $returnValue = $callback();
            $conn->exec("RELEASE SAVEPOINT {$savepoint}");
            return $returnValue;
        } catch (Throwable $e) {
            $this->safeRollbackTo($conn, $savepoint);
            throw $e;
        } finally {
            $context[self::TRANSACTION_DEPTH_KEY] = $depth;
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
            throw new QueryException($sql, $bindings, $e);
        }
    }
}