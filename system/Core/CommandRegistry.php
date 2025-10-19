<?php

declare(strict_types=1);

namespace AavionDB\Core;

use AavionDB\AavionDB;
use AavionDB\Core\Exceptions\CommandException;
use Psr\Log\LoggerInterface;

/**
 * Central registry responsible for storing and executing commands.
 */
final class CommandRegistry
{
    private ?CommandParser $parser = null;

    private ?EventBus $events = null;

    private ?LoggerInterface $logger = null;

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

        if (isset($meta['parser'])) {
            $this->registerParserMetadata($normalized, $meta['parser']);
        }
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
        $start = \microtime(true);

        if ($this->logger !== null && AavionDB::debugEnabled()) {
            $this->logger->debug('Dispatching command.', [
                'action' => $normalized,
                'parameters' => $parameters,
                'source' => 'core:command',
            ]);
        }

        try {
            $result = $handler($parameters);
            $response = CommandResponse::fromPayload($normalized, $result, $parameters);

            if ($this->logger !== null && AavionDB::debugEnabled()) {
                $this->logger->debug('Command execution completed.', [
                    'action' => $normalized,
                    'status' => $response->toArray()['status'] ?? null,
                    'meta' => $response->toArray()['meta'] ?? [],
                    'duration_ms' => (int) ((\microtime(true) - $start) * 1000),
                    'source' => 'core:command',
                ]);
            }

            $this->emit('command.executed', [
                'action' => $normalized,
                'status' => $response->toArray()['status'],
                'meta' => $response->toArray()['meta'] ?? [],
                'duration_ms' => (int) ((\microtime(true) - $start) * 1000),
            ]);

            return $response;
        } catch (\Throwable $exception) {
            $this->logError($normalized, $exception);
            $this->emit('command.failed', [
                'action' => $normalized,
                'exception' => $exception,
                'duration_ms' => (int) ((\microtime(true) - $start) * 1000),
            ]);

            return CommandResponse::error(
                $normalized,
                'Unexpected exception during command execution.',
                [
                    'exception' => [
                        'message' => $exception->getMessage(),
                        'type' => \get_class($exception),
                    ],
                ]
            );
        }
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

    public function setParser(CommandParser $parser): void
    {
        $this->parser = $parser;
    }

    public function setEventBus(EventBus $events): void
    {
        $this->events = $events;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function parser(): ?CommandParser
    {
        return $this->parser;
    }

    /**
     * @param callable(ParserContext): void $handler
     */
    public function registerParserHandler(?string $action, callable $handler, int $priority = 0): void
    {
        if ($this->parser === null) {
            throw new CommandException('No command parser available to register a handler.');
        }

        $this->parser->registerHandler($action, $handler, $priority);
    }

    private function normalizeName(string $name): string
    {
        return \strtolower(\trim($name));
    }

    /**
     * @param mixed $metadata
     */
    private function registerParserMetadata(string $normalizedAction, $metadata): void
    {
        if ($this->parser === null) {
            throw new CommandException(sprintf(
                'Command "%s" declared parser metadata, but no parser is available.',
                $normalizedAction
            ));
        }

        $handlers = $this->normalizeParserMetadata($metadata);

        foreach ($handlers as $handlerDefinition) {
            $action = $handlerDefinition['action'] ?? $normalizedAction;
            $callable = $handlerDefinition['handler'];
            $priority = $handlerDefinition['priority'] ?? 0;
            $this->parser->registerHandler($action, $callable, (int) $priority);
        }
    }

    /**
     * @param mixed $metadata
     *
     * @return array<int, array{handler: callable, action?: string, priority?: int}>
     */
    private function normalizeParserMetadata($metadata): array
    {
        if (\is_callable($metadata)) {
            return [
                [
                    'handler' => $metadata,
                ],
            ];
        }

        if (\is_array($metadata) && isset($metadata['handler']) && \is_callable($metadata['handler'])) {
            return [$metadata];
        }

        if (!\is_array($metadata)) {
            throw new CommandException('Parser metadata must be callable or an array definition.');
        }

        $handlers = [];

        foreach ($metadata as $entry) {
            if (\is_callable($entry)) {
                $handlers[] = ['handler' => $entry];
                continue;
            }

            if (\is_array($entry) && isset($entry['handler']) && \is_callable($entry['handler'])) {
                $handlers[] = $entry;
                continue;
            }

            throw new CommandException('Invalid parser handler definition encountered.');
        }

        return $handlers;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->events === null) {
            return;
        }

        $this->events->emit($event, $payload);
    }

    private function logError(string $action, \Throwable $exception): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->error(sprintf('Command "%s" failed: %s', $action, $exception->getMessage()), [
            'exception' => $exception,
            'action' => $action,
            'source' => 'core:command',
        ]);
    }
}
