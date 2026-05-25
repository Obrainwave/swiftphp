<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Container;

/**
 * Base service provider contract.
 *
 * Service providers are responsible for:
 * - registering bindings
 * - bootstrapping services
 *
 * Concrete providers will live inside the
 * consumer application:
 *
 * app/Providers/
 */
abstract class ServiceProvider
{
    public function __construct(
        protected Container $container
    ) {}

    /**
     * Register container bindings.
     */
    abstract public function register(): void;

    /**
     * Execute post-registration boot logic.
     */
    public function boot(): void
    {
        //
    }
}