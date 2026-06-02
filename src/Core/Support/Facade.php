<?php
declare(strict_types=1);
namespace Swiftphp\Framework\Core\Support;

use Swiftphp\Framework\Core\Container\Container;
use RuntimeException;

abstract class Facade
{
    private static ?Container $container = null;

    /**
     * Boot all facades with the application container.
     * Called once during Application::serve() before server starts.
     */
    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    /**
     * The container binding key this facade resolves to.
     */
    abstract protected static function getFacadeAccessor(): string;

    /**
     * Resolve the underlying instance from the container and forward the call.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        if (self::$container === null) {
            throw new RuntimeException(
                'Facade container not initialised. '
                . 'Ensure Application::serve() has been called.'
            );
        }

        $instance = self::$container->make(static::getFacadeAccessor());

        if (!method_exists($instance, $method)) {
            throw new RuntimeException(
                sprintf(
                    'Method [%s] does not exist on [%s].',
                    $method,
                    $instance::class
                )
            );
        }

        return $instance->$method(...$args);
    }
}