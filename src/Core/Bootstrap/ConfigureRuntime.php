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
        Coroutine::set([
            'hook_flags' => SWOOLE_HOOK_ALL,
        ]);
    }
}