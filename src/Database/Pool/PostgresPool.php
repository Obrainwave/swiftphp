<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Database\Pool;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Concrete PostgreSQL connection pool for SwiftPHP.
 *
 * @extends ConnectionPool<PDO>
 */
final class PostgresPool extends ConnectionPool
{
    /** @var array<string, mixed> Normalized PostgreSQL connection config */
    private array $config;

    /**
     * @param array<string, mixed> $config PostgreSQL connection parameters.
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
            $dns = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database']
            );

            $options = array_replace([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => (int) $this->config['connect_timeout'],
            ], $this->config['pool']);
            try {
                return new PDO(
                    $dns,
                    $this->config['username'],
                    $this->config['password'],
                    $options
                );
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf(
                    'PostgreSQL connection failed [%s@%s:%d/%s]: %s',
                    $this->config['username'],
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['database'],
                    $e->getMessage()
                ), 0, $e);
            }
        };

        $healthCheck = function (PDO $pdo): bool {
            try {
                return $pdo->query('SELECT 1') !== false;
            } catch (PDOException) {
                return false;
            }
        };

        parent::__construct(
            factory: $factory,
            min: $min,
            max: $max,
            timeout: $timeout,
            healthCheck: $healthCheck,
            dialect: 'pgsql'
        );
    }

    private function validateConfig(array $config): void
    {
        foreach (['host', 'database'] as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException(
                    "PostgreSQL pool config requires a non-empty '{$key}' value."
                );
            }
        }

        if (
            isset($config['port']) &&
            (!is_int($config['port']) || $config['port'] <= 0)
        ) {
            throw new InvalidArgumentException(
                'PostgreSQL pool config port must be a positive integer.'
            );
        }
    }

    private function normalizeConfig(array $config): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => 5432,
            'user' => 'postgres',
            'password' => '',
            'database' => '',
            'connect_timeout' => 2,
        ], $config);
    }
}