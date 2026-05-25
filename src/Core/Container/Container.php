<?php

declare(strict_types=1);

namespace Swiftphp\Framework\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Swiftphp\Framework\Core\Coroutine\Context;

/**
 * SwiftPHP Dependency Injection Container.
 *
 * Supports:
 * - transient bindings
 * - singleton bindings
 * - coroutine-scoped bindings
 * - reflection auto-wiring
 */
final class Container implements ContainerInterface
{
    /**
     * Transient bindings.
     *
     * @var array<string, Closure|string>
     */
    private array $bindings = [];

    /**
     * Singleton binding definitions.
     *
     * @var array<string, Closure|string>
     */
    private array $singletonBindings = [];

    /**
     * Singleton instances.
     *
     * @var array<string, mixed>
     */
    private array $singletons = [];

    /**
     * Coroutine-scoped bindings.
     *
     * @var array<string, Closure|string>
     */
    private array $scopedBindings = [];

    private readonly ResolverInterface $resolver;

    public function __construct(?ResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new Resolver($this);
    }

    /**
     * Register transient binding.
     *
     * New instance every resolution.
     */
    public function bind(
        string $abstract,
        Closure|string $concrete
    ): void {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Register singleton binding.
     *
     * Shared globally across worker lifecycle.
     */
    public function singleton(
        string $abstract,
        Closure|string $concrete
    ): void {
        $this->singletonBindings[$abstract] = $concrete;
    }

    /**
     * Register coroutine-scoped binding.
     *
     * One instance per coroutine/request.
     */
    public function scoped(
        string $abstract,
        Closure|string $concrete
    ): void {
        $this->scopedBindings[$abstract] = $concrete;
    }

    /**
     * Resolve dependency.
     */
    public function make(string $abstract): mixed
    {
        /**
         * Scoped bindings
         */
        if (isset($this->scopedBindings[$abstract])) {

            $scoped = Context::get('__scoped__', []);

            /**
             * Already resolved in this coroutine
             */
            if (isset($scoped[$abstract])) {
                return $scoped[$abstract];
            }

            $instance = $this->resolveBinding(
                $this->scopedBindings[$abstract]
            );

            $scoped[$abstract] = $instance;

            Context::set('__scoped__', $scoped);

            return $instance;
        }

        /**
         * Singleton bindings
         */
        if (isset($this->singletonBindings[$abstract])) {

            if (isset($this->singletons[$abstract])) {
                return $this->singletons[$abstract];
            }

            return $this->singletons[$abstract]
                = $this->resolveBinding(
                    $this->singletonBindings[$abstract]
                );
        }

        /**
         * Transient bindings
         */
        if (isset($this->bindings[$abstract])) {

            return $this->resolveBinding(
                $this->bindings[$abstract]
            );
        }

        /**
         * Auto-wire unresolved concrete class.
         */
        return $this->resolver->resolve($abstract);
    }

    /**
     * PSR-11 get()
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * PSR-11 has()
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->singletonBindings[$id])
            || isset($this->scopedBindings[$id]);
    }

    /**
     * Resolve binding definition.
     */
    private function resolveBinding(
        Closure|string $concrete
    ): mixed {

        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        return $this->resolver->resolve($concrete);
    }
}