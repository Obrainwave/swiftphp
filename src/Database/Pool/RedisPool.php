<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Database\Pool;

use Redis;
use RedisException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Concrete Redis connection pool for SwiftPHP.
 *
 * @extends ConnectionPool<Redis>
 */
final class RedisPool extends ConnectionPool
{
    /** @var array<string, mixed> Normalized Redis connection config */
    private array $config;

    /**
     * @param array<string, mixed> $config Redis connection parameters.
     * @param int   $min         Minimum connections to keep warm.
     * @param int   $max         Maximum connections allowed in the pool.
     * @param float $timeout Seconds to wait when the pool is saturated.
     */
    public function __construct(
        array $config = [],
        int $min = 5,
        int $max = 64,
        float $timeout = 3.0
    ) {
        // Validate user inputs before flattening with defaults
        $this->validateConfig($config);
        $this->config = $this->normalizeConfig($config);

        // Define how a fresh native Redis instance is fabricated
        $factory = function (): Redis {
            $redis = new Redis();

            try {
                $connected = $redis->connect(
                    (string) $this->config['host'],
                    (int) $this->config['port'],
                    (float) $this->config['timeout']
                );

                if (!$connected) {
                    $error = $redis->getLastError() ?? 'unknown error';
                    throw new RuntimeException(sprintf(
                        'Redis connection failed [%s:%d]: %s',
                        $this->config['host'],
                        $this->config['port'],
                        $error
                    ));
                }

                // Handle authentication (supports string password or Redis 6+ ACL arrays)
                if (!empty($this->config['password'])) {
                    if (!$redis->auth($this->config['password'])) {
                        throw new RuntimeException(sprintf(
                            'Redis authentication failed [%s:%d]: %s',
                            $this->config['host'],
                            $this->config['port'],
                            $redis->getLastError() ?? 'Invalid credentials'
                        ));
                    }
                }

                // Handle database selection
                $dbIndex = (int) $this->config['database'];
                if ($dbIndex !== 0) {
                    if (!$redis->select($dbIndex)) {
                        throw new RuntimeException(sprintf(
                            'Redis failed to select database %d [%s:%d]',
                            $dbIndex,
                            $this->config['host'],
                            $this->config['port']
                        ));
                    }
                }

                // Apply default option protections alongside user-defined choices
                $options = array_replace([
                    Redis::OPT_READ_TIMEOUT => (float) $this->config['read_timeout'],
                ], $this->config['options']);

                foreach ($options as $optionKey => $optionValue) {
                    $redis->setOption($optionKey, $optionValue);
                }

                return $redis;
            } catch (RedisException $e) {
                throw new RuntimeException(sprintf(
                    'Redis connection exception [%s:%d]: %s',
                    $this->config['host'],
                    $this->config['port'],
                    $e->getMessage()
                ), 0, $e);
            }
        };

        // Active I/O health check via PING
        $healthCheck = function (Redis $conn): bool {
            try {
                $response = $conn->ping();
                return $response === true || $response === '+PONG' || $response === 'PONG';
            } catch (RedisException) {
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

    /**
     * Guardrail checking incoming raw array configurations.
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException
     */
    private function validateConfig(array $config): void
    {
        if (
            isset($config['port']) &&
            (!is_int($config['port']) || $config['port'] <= 0)
        ) {
            throw new InvalidArgumentException(
                'Redis pool config port must be a positive integer.'
            );
        }

        if (
            isset($config['timeout']) &&
            (!is_numeric($config['timeout']) || $config['timeout'] <= 0)
        ) {
            throw new InvalidArgumentException(
                'Redis timeout must be greater than zero.'
            );
        }

        if (
            isset($config['read_timeout']) &&
            (!is_numeric($config['read_timeout']) || $config['read_timeout'] <= 0)
        ) {
            throw new InvalidArgumentException(
                'Redis read_timeout must be greater than zero.'
            );
        }
    }

    /**
     * Merge user config with sane defaults.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 2.0,
            'read_timeout' => 2.0,
            'options' => [],
        ], $config);
    }
}