<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Database\Pool;

use RuntimeException;

final class PoolFactory
{
    /**
     * Create the correct pool type based on the driver key in config.
     */
    public static function make(string $name, array $config): ConnectionPool
    {
        $driver = $config['driver'] ?? null;

        if ($driver === null) {
            throw new RuntimeException(
                "Database connection [{$name}] is missing a 'driver' key in config."
            );
        }

        $pool = $config['pool'] ?? [];

        return match ($driver) {
            'mysql' => new MysqlPool(
                config:  $config,
                min:     (int)   ($pool['min']     ?? 5),
                max:     (int)   ($pool['max']     ?? 50),
                timeout: (float) ($pool['timeout'] ?? 3.0),
            ),
            'pgsql' => new PostgresPool(
                config:  $config,
                min:     (int)   ($pool['min']     ?? 5),
                max:     (int)   ($pool['max']     ?? 50),
                timeout: (float) ($pool['timeout'] ?? 3.0),
            ),
            default => throw new RuntimeException(
                "Unsupported database driver [{$driver}] for connection [{$name}]. "
                . "Supported: mysql, pgsql."
            ),
        };
    }
}