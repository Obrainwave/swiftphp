<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Pool;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Concrete MySQL connection pool for SwiftPHP.
 *
 * @extends ConnectionPool<PDO>
 */
final class MysqlPool extends ConnectionPool
{
    /** @var array<string, mixed> Normalized MySQL connection config */
    private array $config;

    /**
     * @param array<string, mixed> $config MySQL connection parameters.
     * @param int   $min     Minimum connections to keep warm.
     * @param int   $max     Maximum connections allowed in the pool.
     * @param float $timeout Seconds to wait when the pool is saturated.
     */
    public function __construct(
        array $config,
        int $min = 5,
        int $max = 64,
        float $timeout = 3.0
    ) {
        $this->validateConfig($config);
        $this->config = $this->normalizeConfig($config);

        // Define how a fresh PDO instance is fabricated
        $factory = function (): PDO {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );

            // Merge sane defaults with user-defined driver choices
            $options = array_replace([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => (int) $this->config['timeout'],
            ], $this->config['options']);

            try {
                return new PDO($dsn, $this->config['user'], $this->config['password'], $options);
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf(
                    'MySQL connection failed [%s@%s:%d/%s]: %s',
                    $this->config['user'],
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $e->getMessage()
                ), 0, $e);
            }
        };

        // Active I/O health check
        $healthCheck = function (PDO $conn): bool {
            try {
                $conn->query('SELECT 1');
                return true;
            } catch (PDOException) {
                return false;
            }
        };

        parent::__construct(
            factory: $factory,
            min: $min,
            max: $max,
            timeout: $timeout,
            healthCheck: $healthCheck
        );
    }

    private function validateConfig(array $config): void
    {
        foreach (['host', 'database'] as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException(
                    "MySQL pool config requires a non-empty '{$key}' value."
                );
            }
        }

        if (
            isset($config['port']) &&
            (!is_int($config['port']) || $config['port'] <= 0)
        ) {
            throw new InvalidArgumentException(
                'MySQL pool config port must be a positive integer.'
            );
        }
    }

    private function normalizeConfig(array $config): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'database' => '',
            'charset' => 'utf8mb4',
            'timeout' => 2,
            'options' => [], // Sane open gateway for custom PDO parameters
        ], $config);
    }
}