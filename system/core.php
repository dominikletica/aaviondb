<?php

declare(strict_types=1);

namespace AavionDB;

use AavionDB\Core\Bootstrap;
use AavionDB\Core\CommandParser;
use AavionDB\Core\CommandRegistry;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\EventBus;
use AavionDB\Core\Exceptions\CommandException;
use AavionDB\Core\RuntimeState;
use AavionDB\Core\Security\AuthManager;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;

/**
 * Framework façade exposing runtime lifecycle and command execution entry points.
 */
final class AavionDB
{
    private static ?RuntimeState $state = null;

    /**
     * @var array<string, mixed>
     */
    private static array $bootstrapOptions = [];

    /**
     * Bootstraps the framework. May only be invoked once per request lifecycle.
     *
     * @param array<string, mixed> $options
     */
    public static function setup(array $options = []): void
    {
        if (self::$state !== null) {
            return;
        }

        self::$bootstrapOptions = $options;

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
            return self::errorResponse('invalid', 'Command action must not be empty.');
        }

        /** @var CommandRegistry $registry */
        $registry = self::$state->container()->get(CommandRegistry::class);
        
        try {
            $response = $registry->dispatch($action, $parameters);

            return $response->toArray();
        } catch (CommandException $exception) {
            self::logger()->warning($exception->getMessage(), ['action' => $action]);

            return self::errorResponse($action, $exception->getMessage());
        } catch (\Throwable $exception) {
            self::logger()->error('Unhandled command exception', [
                'action' => $action,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return self::errorResponse($action, 'Internal error during command execution.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => \get_class($exception),
                ],
            ]);
        }
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

        try {
            $parsed = $parser->parse($statement);

            return self::run($parsed->action(), $parsed->parameters());
        } catch (CommandException $exception) {
            self::logger()->warning($exception->getMessage(), ['statement' => $statement]);

            return self::errorResponse('parse', $exception->getMessage(), [
                'statement' => $statement,
            ]);
        } catch (\Throwable $exception) {
            self::logger()->error('Parser failure', [
                'statement' => $statement,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return self::errorResponse('parse', 'Internal error while parsing command.', [
                'statement' => $statement,
            ]);
        }
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
     * Returns the framework logger instance.
     */
    public static function logger(): LoggerInterface
    {
        self::assertBooted();

        /** @var LoggerInterface $logger */
        $logger = self::$state->container()->get(LoggerInterface::class);

        return $logger;
    }

    /**
     * Returns the authentication manager.
     */
    public static function auth(): AuthManager
    {
        self::assertBooted();

        /** @var AuthManager $auth */
        $auth = self::$state->container()->get(AuthManager::class);

        return $auth;
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
        self::$bootstrapOptions = [];
    }

    private static function assertBooted(): void
    {
        if (self::$state === null) {
            self::setup(self::$bootstrapOptions);
        }
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function errorResponse(string $action, string $message, array $meta = []): array
    {
        return [
            'status' => 'error',
            'action' => $action,
            'message' => $message,
            'data' => null,
            'meta' => $meta,
        ];
    }
}
