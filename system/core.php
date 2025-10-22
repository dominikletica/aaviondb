<?php

declare(strict_types=1);

namespace AavionDB;

use AavionDB\Core\Bootstrap;
use AavionDB\Core\CommandParser;
use AavionDB\Core\CommandRegistry;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\Cache\CacheManager;
use AavionDB\Core\EventBus;
use AavionDB\Core\Exceptions\CommandException;
use AavionDB\Core\RuntimeState;
use AavionDB\Core\Security\AuthManager;
use AavionDB\Core\Security\SecurityManager;
use AavionDB\Core\Storage\BrainRepository;
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
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * @var array{mode: string, projects: array<int, string>}
     */
    private static array $currentScope = [
        'mode' => 'ALL',
        'projects' => ['*'],
    ];

    private static bool $debug = false;

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

        $config = self::configuration($options);
        self::$bootstrapOptions = $config;

        $bootstrap = new Bootstrap(\dirname(__DIR__), $config);
        self::$state = $bootstrap->boot($config);
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

        $debug = self::extractDebugFlag($parameters);
        $previousDebug = self::$debug;
        if ($debug !== null) {
            self::$debug = $debug;
        }

        /** @var CommandRegistry $registry */
        $registry = self::$state->container()->get(CommandRegistry::class);
        
        try {
            $response = $registry->dispatch($action, $parameters);

            return $response->toArray();
        } catch (CommandException $exception) {
            self::logger()->warning($exception->getMessage(), [
                'action' => $action,
                'source' => 'core:facade',
            ]);

            return self::errorResponse($action, $exception->getMessage());
        } catch (\Throwable $exception) {
            self::logger()->error('Unhandled command exception', [
                'action' => $action,
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'source' => 'core:facade',
            ]);

            return self::errorResponse($action, 'Internal error during command execution.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => \get_class($exception),
                ],
            ]);
        } finally {
            self::$debug = $previousDebug;
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
            self::logger()->warning($exception->getMessage(), [
                'statement' => $statement,
                'source' => 'core:facade',
            ]);

            return self::errorResponse('parse', $exception->getMessage(), [
                'statement' => $statement,
            ]);
        } catch (\Throwable $exception) {
            self::logger()->error('Parser failure', [
                'statement' => $statement,
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'source' => 'core:facade',
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
     * Returns the loaded configuration.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return self::$config;
    }

    /**
     * Returns the current access scope.
     *
     * @return array{mode: string, projects: array<int, string>}
     */
    public static function scope(): array
    {
        return self::$currentScope;
    }

    /**
     * Executes a callback within a temporary access scope.
     *
     * @param array{mode?: string, projects?: array<int, string>|string} $scope
     * @param callable(): array<string, mixed>                              $callback
     */
    public static function withScope(array $scope, callable $callback): array
    {
        $previous = self::$currentScope;
        self::$currentScope = self::normalizeScope($scope);

        try {
            return $callback();
        } finally {
            self::$currentScope = $previous;
        }
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
     * Returns the cache manager service.
     */
    public static function cache(): CacheManager
    {
        self::assertBooted();

        /** @var CacheManager $cache */
        $cache = self::$state->container()->get(CacheManager::class);

        return $cache;
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
     * Returns the security manager.
     */
    public static function security(): SecurityManager
    {
        self::assertBooted();

        /** @var SecurityManager $security */
        $security = self::$state->container()->get(SecurityManager::class);

        return $security;
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
        self::$debug = false;
    }

    public static function debugEnabled(): bool
    {
        return self::$debug;
    }

    public static function debugLog(string $message, array $context = []): void
    {
        if (!self::debugEnabled()) {
            return;
        }

        if (!isset($context['source'])) {
            $context['source'] = 'core:debug';
        }

        $context['debug'] = true;

        self::logger()->debug($message, $context);
    }

    private static function extractDebugFlag(array &$parameters): ?bool
    {
        $debug = null;

        if (\array_key_exists('debug', $parameters)) {
            $candidate = self::normalizeBool($parameters['debug']);
            if ($candidate !== null) {
                $debug = $candidate;
                $parameters['debug'] = $candidate;
            } else {
                unset($parameters['debug']);
            }
        }

        if (isset($parameters['metadata']) && \is_array($parameters['metadata']) && \array_key_exists('debug', $parameters['metadata'])) {
            $candidate = self::normalizeBool($parameters['metadata']['debug']);
            if ($candidate !== null) {
                $debug = $candidate;
                $parameters['metadata']['debug'] = $candidate;
            } else {
                unset($parameters['metadata']['debug']);
            }
        }

        return $debug;
    }

    private static function normalizeBool($value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));

            if ($normalized === '') {
                return null;
            }

            if (\in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (\in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function configuration(array $overrides = []): array
    {
        if (self::$config === []) {
            $root = \dirname(__DIR__);

            $defaults = [
                'admin_secret' => '',
                'default_brain' => 'default',
                'backups_path' => 'user/backups',
                'exports_path' => 'user/exports',
                'log_path' => 'system/storage/logs',
                'api_key_length' => 16,
            ];

            $config = [];
            $file = $root . DIRECTORY_SEPARATOR . 'config.php';

            if (\is_file($file)) {
                $loaded = require $file;
                if (\is_array($loaded)) {
                    $config = $loaded;
                }
            }

            self::$config = \array_merge($defaults, $config);
        }

        self::$config = \array_merge(self::$config, $overrides);

        return self::$config;
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

    /**
     * @param array{mode?: string, scope?: string, projects?: array<int, string>|string} $scope
     *
     * @return array{mode: string, projects: array<int, string>}
     */
    private static function normalizeScope(array $scope): array
    {
        $mode = \strtoupper((string) ($scope['mode'] ?? $scope['scope'] ?? 'ALL'));

        $projects = $scope['projects'] ?? ['*'];

        if (!\is_array($projects)) {
            $projects = \array_map('trim', \explode(',', (string) $projects));
        }

        $projects = \array_values(\array_filter(\array_map(static function ($value) {
            if ($value === null) {
                return null;
            }

            $value = \strtolower((string) $value);
            return $value === '' ? null : $value;
        }, $projects), static fn ($value) => $value !== null));

        if ($projects === [] || \in_array('*', $projects, true)) {
            $projects = ['*'];
        }

        return [
            'mode' => $mode,
            'projects' => $projects,
        ];
    }
}
