<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Container;


use Swiftphp\Framework\Core\Container\Exception\ContainerException;
use Swiftphp\Framework\Core\Container\Exception\NotFoundException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Reflection-based auto-wiring resolver.
 *
 * Responsible ONLY for:
 * - constructor inspection
 * - recursive dependency resolution
 * - object instantiation
 */
final class Resolver implements ResolverInterface
{
    public function __construct(
        private readonly Container $container
    ) {
    }

    /**
     * Resolve class dependencies recursively.
     */
    public function resolve(string $class): mixed
    {
        if (!class_exists($class)) {
            throw new NotFoundException(            
                "No binding registered for [{$class}] and class does not exist."
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new NotFoundException(            
                "[{$class}] is not instantiable. Register a concrete binding in a ServiceProvider."
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new ContainerException(        // ← was RuntimeException
                    "Cannot resolve primitive parameter [{$parameter->getName()}] "
                    . "in [{$class}]. Pass it explicitly or use a Closure binding."
                );
            }

            $dependencies[] = $this->container->make($type->getName());
        }

        try {
            return $reflection->newInstanceArgs($dependencies);
        } catch (\Throwable $e) {
            throw new ContainerException(          
                "Error instantiating [{$class}]: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}