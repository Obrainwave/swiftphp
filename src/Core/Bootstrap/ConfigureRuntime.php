<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Core\Bootstrap;

use Swoole\Coroutine;

/**
 * Configures the Swoole coroutine runtime.
 * Must be called once before the server starts accepting requests.
 */
final class ConfigureRuntime
{
    public static function boot(): void
    {
        /**
         * Load environment variables.
         */
        $basePath = dirname(__DIR__, 3);
        $dotenv = \Dotenv\Dotenv::createImmutable($basePath);
        $dotenv->safeLoad();

        /**
         * Load helper functions.
         */
        require_once __DIR__ . '/../Helper/helpers.php';

        /**
         * Enable coroutine hooks for all supported operations.
         * This allows blocking calls to be automatically converted to non-blocking within coroutines.
         */
        Coroutine::set([
            'hook_flags' => SWOOLE_HOOK_ALL,
        ]);
    }
}