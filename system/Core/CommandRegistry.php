<?php

declare(strict_types=1);

namespace AavionDB\Core;

use AavionDB\Core\Exceptions\CommandException;

/**
 * Central registry responsible for storing and executing commands.
 */
final class CommandRegistry
{
    /**
     * @var array<string, array{name: string, handler: callable, meta: array<string, mixed>}>
     */
    private array $commands = [];

    /**
     * Registers a new command handler.
     *
     * @param callable(array<string, mixed>): mixed $handler
     * @param array<string, mixed>                 $meta
     */
    public function register(string $name, callable $handler, array $meta = []): void
    {
        $normalized = $this->normalizeName($name);
        if (isset($this->commands[$normalized])) {
            throw new CommandException(sprintf('Command "%s" has already been registered.', $name));
        }

        $this->commands[$normalized] = [
            'name' => $normalized,
            'handler' => $handler,
            'meta' => $meta,
        ];
    }

    /**
     * Executes a registered command.
     *
     * @param array<string, mixed> $parameters
     */
    public function dispatch(string $name, array $parameters = []): CommandResponse
    {
        $normalized = $this->normalizeName($name);

        if (!isset($this->commands[$normalized])) {
            throw new CommandException(sprintf('Command "%s" is not registered.', $name));
        }

        $entry = $this->commands[$normalized];
        /** @var callable(array<string, mixed>): mixed $handler */
        $handler = $entry['handler'];
        $result = $handler($parameters);

        return CommandResponse::fromPayload($normalized, $result, $parameters);
    }

    /**
     * Returns metadata for all registered commands.
     *
     * @return array<int, array{name: string, meta: array<string, mixed>}>
     */
    public function all(): array
    {
        $list = [];
        foreach ($this->commands as $command) {
            $list[] = [
                'name' => $command['name'],
                'meta' => $command['meta'],
            ];
        }

        return $list;
    }

    private function normalizeName(string $name): string
    {
        return \strtolower(\trim($name));
    }
}

