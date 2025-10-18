<?php

declare(strict_types=1);

namespace AavionDB\Core;

use Closure;
use RuntimeException;

/**
 * Lightweight service container for lazy-loading shared services.
 */
final class Container
{
    /**
     * @var array<string, callable(self): mixed>
     */
    private array $definitions = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Registers a service factory.
     *
     * @param callable(self): mixed $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    /**
     * Checks whether a service definition exists.
     */
    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->instances[$id]);
    }

    /**
     * Retrieves a service instance (instantiating lazily when needed).
     *
     * @template T
     *
     * @param class-string<T>|string $id
     *
     * @return T
     */
    public function get(string $id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new RuntimeException(sprintf('Service "%s" has not been registered.', $id));
        }

        /** @var callable(self): mixed $factory */
        $factory = $this->definitions[$id];
        $instance = $factory($this);

        $this->instances[$id] = $instance;

        return $instance;
    }

    /**
     * Replaces an existing instance (used primarily for tests).
     */
    public function setInstance(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }
}

