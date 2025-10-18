<?php

declare(strict_types=1);

namespace AavionDB;

use AavionDB\Core\Bootstrap;
use AavionDB\Core\CommandParser;
use AavionDB\Core\CommandRegistry;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\EventBus;
use AavionDB\Core\Exceptions\BootstrapException;
use AavionDB\Core\Exceptions\CommandException;
use AavionDB\Core\RuntimeState;
use AavionDB\Storage\BrainRepository;

/**
 * Framework façade exposing runtime lifecycle and command execution entry points.
 */
final class AavionDB
{
    private static ?RuntimeState $state = null;

    /**
     * Bootstraps the framework. May only be invoked once per request lifecycle.
     *
     * @param array<string, mixed> $options
     */
    public static function setup(array $options = []): void
    {
        if (self::$state !== null) {
            throw new BootstrapException('AavionDB::setup() may only be called once per request lifecycle.');
        }

        $bootstrap = new Bootstrap(\dirname(__DIR__));
        self::$state = $bootstrap->boot($options);
    }

    /**
     * Executes a command by name using the unified dispatcher.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public static function run(string $action, array $parameters = []): array
    {
        self::assertBooted();

        $action = \trim($action);
        if ($action === '') {
            throw new CommandException('Command action must not be empty.');
        }

        /** @var CommandRegistry $registry */
        $registry = self::$state->container()->get(CommandRegistry::class);

        $response = $registry->dispatch($action, $parameters);

        return $response->toArray();
    }

    /**
     * Parses and executes a human-readable command statement.
     *
     * @return array<string, mixed>
     */
    public static function command(string $statement): array
    {
        self::assertBooted();

        /** @var CommandParser $parser */
        $parser = self::$state->container()->get(CommandParser::class);
        $parsed = $parser->parse($statement);

        return self::run($parsed->action(), $parsed->parameters());
    }

    /**
     * Registers a custom parser handler via the shared command registry.
     *
     * @param callable(AavionDB\Core\ParserContext): void $handler
     */
    public static function registerParserHandler(?string $action, callable $handler, int $priority = 0): void
    {
        self::commands()->registerParserHandler($action, $handler, $priority);
    }

    /**
     * Returns diagnostic information collected during bootstrap.
     *
     * @return array<string, mixed>
     */
    public static function diagnose(): array
    {
        self::assertBooted();

        return [
            'status' => 'ok',
            'message' => 'AavionDB diagnostics snapshot',
            'data' => self::$state->diagnostics(),
        ];
    }

    /**
     * Returns the shared event bus instance.
     */
    public static function events(): EventBus
    {
        self::assertBooted();

        /** @var EventBus $bus */
        $bus = self::$state->container()->get(EventBus::class);

        return $bus;
    }

    /**
     * Returns the shared command registry.
     */
    public static function commands(): CommandRegistry
    {
        self::assertBooted();

        /** @var CommandRegistry $registry */
        $registry = self::$state->container()->get(CommandRegistry::class);

        return $registry;
    }

    /**
     * Returns the command parser instance.
     */
    public static function parser(): CommandParser
    {
        self::assertBooted();

        /** @var CommandParser $parser */
        $parser = self::$state->container()->get(CommandParser::class);

        return $parser;
    }

    /**
     * Returns the active brain repository instance.
     */
    public static function brains(): BrainRepository
    {
        self::assertBooted();

        /** @var BrainRepository $repository */
        $repository = self::$state->container()->get(BrainRepository::class);

        return $repository;
    }

    /**
     * Indicates whether the framework has completed bootstrap.
     */
    public static function isBooted(): bool
    {
        return self::$state !== null;
    }

    /**
     * Resets internal runtime state (for tests). Not intended for production use.
     */
    public static function _resetForTests(): void
    {
        self::$state = null;
    }

    private static function assertBooted(): void
    {
        if (self::$state === null) {
            throw new BootstrapException('AavionDB::setup() must be called before invoking runtime methods.');
        }
    }
}
