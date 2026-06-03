<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Exceptions;

use PDOException;
use Throwable;

/**
 * Pure database exception carrier.
 *
 * Responsibilities:
 * - Transport SQL error metadata
 * - Preserve driver + SQLSTATE information
 * - Provide deterministic structured export
 *
 * Non-responsibilities:
 * - Logging
 * - Masking
 * - Policy enforcement
 * - Formatting decisions
 */
final class QueryException extends PDOException
{
    private ?string $sql = null;

    /** @var array<int|string, mixed> */
    private array $bindings = [];

    private ?string $connection = null;

    /**
     * Canonical SQLSTATE (string only)
     */
    private string $sqlState = '00000';

    /**
     * Raw driver error info: [SQLSTATE, driver_code, driver_message]
     */
    public ?array $errorInfo = null;

    public function __construct(
        string $message,
        ?string $sql = null,
        array $bindings = [],
        array $errorInfo = [],
        ?Throwable $previous = null,
        ?string $connection = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->connection = $connection;

        $this->errorInfo = $errorInfo;

        $this->sqlState = $this->resolveSqlState($errorInfo, $previous);
    }

    /**
     * Resolve SQLSTATE in a deterministic way.
     */
    private function resolveSqlState(array $errorInfo, ?Throwable $previous): string
    {
        if (isset($errorInfo[0]) && is_string($errorInfo[0])) {
            return $errorInfo[0];
        }

        if ($previous instanceof PDOException && is_string($previous->getCode())) {
            return $previous->getCode();
        }

        return '00000';
    }

    public function getSql(): ?string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getConnection(): ?string
    {
        return $this->connection;
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }

    public function getErrorInfo(): array
    {
        return $this->errorInfo;
    }

    /**
     * Structured export for external logging systems.
     */
    public function toArray(): array
    {
        return [
            'message'    => $this->getMessage(),
            'sql'        => $this->sql,
            'bindings'   => $this->bindings,
            'connection' => $this->connection,
            'sqlstate'   => $this->sqlState,
            'driver'     => [
                'code'    => $this->errorInfo[1] ?? null,
                'message' => $this->errorInfo[2] ?? null,
            ],
            'file'       => $this->getFile(),
            'line'       => $this->getLine(),
        ];
    }

    /**
     * Minimal string form for CLI or emergency logs.
     */
    public function __toString(): string
    {
        return sprintf(
            "[%s] %s (SQLSTATE %s) in %s:%d\nSQL: %s",
            self::class,
            $this->getMessage(),
            $this->sqlState,
            $this->getFile(),
            $this->getLine(),
            $this->sql ?? 'N/A'
        );
    }
}