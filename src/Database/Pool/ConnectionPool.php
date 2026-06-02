<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Pool;

use Swoole\Coroutine\Channel;
use RuntimeException;
use Throwable;

/**
 * Generic asynchronous connection pool.
 *
 * @template TConnection
 */
class ConnectionPool
{
    /** @var Channel<TConnection> */
    private Channel $channel;

    /** @var callable(): TConnection */
    private $factory;

    /** @var callable(TConnection): bool */
    private $healthCheck;

    private int $min;
    private int $max;
    private float $timeout;
    private int $allocatedCount = 0;
    private bool $closed = false;
    private string $dialect;

    /**
     * @param callable(): TConnection $factory
     * @param callable(TConnection): bool|null $healthCheck
     */
    public function __construct(
        callable $factory,
        int $min = 1,
        int $max = 10,
        float $timeout = 3.0,
        ?callable $healthCheck = null,
        string $dialect = 'mysql'
    ) {
        if ($min < 0 || $max <= 0) {
            throw new RuntimeException('Pool size must be positive integers.');
        }
        if ($min > $max) {
            throw new RuntimeException('Minimum pool size cannot be greater than maximum pool size.');
        }

        $this->factory = $factory;
        // Fallback to a transparent true validator if no healthcheck is provided
        $this->healthCheck = $healthCheck ?? fn(mixed $conn): bool => true;
        $this->min = $min;
        $this->max = $max;
        $this->timeout = $timeout;
        $this->dialect = $dialect;
        $this->channel = new Channel($max);

        // Pre-populate baseline capacity
        $this->warm($min);
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    /**
     * @return TConnection
     */
    public function acquire(?float $timeoutOverride = null): mixed
    {
        $timeout = $timeoutOverride ?? $this->timeout;

        while (true) {
            // Guard 1: Instant rejection if pool was closed outside the loop
            if ($this->closed) {
                throw new RuntimeException('Connection pool has been closed.');
            }

            // Strategy 1: Non-blocking hot path for warm idle connections
            if ($this->channel->length() > 0) {
                $conn = $this->channel->pop(0.001);
                if ($conn !== false) {
                    if ($this->validateHealth($conn)) {
                        return $conn;
                    }
                    continue;
                }
            }

            // Strategy 2: Zero-delay on-the-fly scaling up to maximum ceiling
            if ($this->allocatedCount < $this->max) {
                try {
                    $this->allocatedCount++;
                    $conn = ($this->factory)();
                    if ($this->validateHealth($conn)) {
                        return $conn;
                    }
                    continue;
                } catch (Throwable $e) {
                    $this->allocatedCount--;
                    throw new RuntimeException('Failed to scale connection pool dynamically: ' . $e->getMessage(), 0, $e);
                }
            }

            // Strategy 3: Saturated pool. Yield execution until a socket returns or timeout expires
            $conn = $this->channel->pop($timeout);

            // Guard 2: Catch channel closure race conditions while this coroutine was yielded
            if ($this->closed) {
                throw new RuntimeException('Connection pool was closed while awaiting acquisition slot.');
            }

            if ($conn === false) {
                throw new RuntimeException('Connection pool acquire timeout. All resources saturated.');
            }

            if ($this->validateHealth($conn)) {
                return $conn;
            }
        }
    }

    /**
     * @param TConnection $connection
     */
    public function release(mixed $connection): void
    {
        /**
         * Defensive Guard: If the pool was closed while this connection was checked out,
         * or the channel is abnormally full, discard it immediately.
         */
        if ($this->closed || $this->channel->isFull()) {
            $this->closeConnection($connection);
            $this->allocatedCount--;
            return;
        }

        $this->channel->push($connection);
    }

    /**
     * Permanently remove a dead connection from the pool without returning it.
     * Used when a fatal connection error makes the socket unusable.
     */
    public function discard(mixed $connection): void
    {
        $this->closeConnection($connection);
        $this->allocatedCount--;
    }

    public function warm(int $count): void
    {
        $toCreate = min($count, $this->max - $this->allocatedCount);
        for ($i = 0; $i < $toCreate; $i++) {
            try {
                $conn = ($this->factory)();
                $this->allocatedCount++;
                $this->channel->push($conn);
            } catch (Throwable $e) {
                // Prevent bootstrap crashes if external dependency is temporarily unreachable
                break;
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        while (!$this->channel->isEmpty()) {
            $conn = $this->channel->pop(0.001);
            if ($conn !== false) {
                $this->closeConnection($conn);
            }
        }

        $this->channel->close();
        $this->allocatedCount = 0;
    }

    private function validateHealth(mixed $connection): bool
    {
        try {
            if (($this->healthCheck)($connection) === true) {
                return true;
            }
        } catch (Throwable $e) {
            // Treat validation panics as structural connection failure
        }

        $this->closeConnection($connection);
        $this->allocatedCount--;
        return false;
    }

    private function closeConnection(mixed $connection): void
    {
        if (method_exists($connection, 'close')) {
            try {
                $connection->close();
            } catch (Throwable $e) {
                // Suppress downstream socket close exceptions during runtime teardowns
            }
        }
    }

    public function allocatedCount(): int
    {
        return $this->allocatedCount;
    }

    public function idleCount(): int
    {
        return $this->channel->length();
    }
}