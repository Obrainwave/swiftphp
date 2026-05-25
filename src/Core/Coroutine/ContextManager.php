<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Core\Coroutine;

use Swoole\Coroutine;
use Swoole\Event;

/**
 * Manages coroutine context lifecycle.
 * Registers hooks that automatically destroy context
 * when a coroutine finishes — preventing memory leaks
 * and cross-request state bleeding.
 */
final class ContextManager
{
    public static function register(): void
    {
        Event::defer(static function(): void {
            $cid = Coroutine::getCid();
            if ($cid > 0) {
                Context::destroy($cid);
            }
        });
    }
}