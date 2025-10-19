<?php

declare(strict_types=1);

namespace AavionDB\Core\Modules;

use DateTimeImmutable;
use AavionDB\Core\CommandRegistry;
use AavionDB\Core\Container;
use AavionDB\Core\EventBus;
use AavionDB\Core\Filesystem\PathLocator;
use Psr\Log\LoggerInterface;

/**
 * Discovers and manages system and user modules.
 */
final class ModuleLoader
{
    private const DEFAULT_CAPABILITIES = [
        ModuleDescriptor::SCOPE_SYSTEM => [
            'container.access',
            'commands.register',
            'events.dispatch',
            'parser.extend',
            'paths.read',
            'logger.use',
            'storage.read',
            'storage.write',
            'security.manage',
            'cache.manage',
        ],
        ModuleDescriptor::SCOPE_USER => [
            'logger.use',
            'paths.read',
            'events.dispatch',
        ],
    ];

    private const ALLOWED_CAPABILITIES = [
        ModuleDescriptor::SCOPE_SYSTEM => [
            'container.access',
            'commands.register',
            'events.dispatch',
            'parser.extend',
            'paths.read',
            'logger.use',
            'storage.read',
            'storage.write',
            'security.manage',
            'cache.manage',
        ],
        ModuleDescriptor::SCOPE_USER => [
            'commands.register',
            'events.dispatch',
            'parser.extend',
            'paths.read',
            'logger.use',
            'storage.read',
            'storage.write',
        ],
    ];

    private PathLocator $paths;

    private Container $container;

    private CommandRegistry $commands;

    private EventBus $events;

    private LoggerInterface $logger;

    /**
     * @var array<string, ModuleDescriptor>
     */
    private array $modules = [];

    /**
     * @var array<int, string>
     */
    private array $errors = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $initialised = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $initialisationErrors = [];

    /**
     * @var array<string, bool>
     */
    private array $initialising = [];

    public function __construct(
        PathLocator $paths,
        Container $container,
        CommandRegistry $commands,
        EventBus $events,
        LoggerInterface $logger
    ) {
        $this->paths = $paths;
        $this->container = $container;
        $this->commands = $commands;
        $this->events = $events;
        $this->logger = $logger;
    }

    public function discover(): void
    {
        $this->modules = [];
        $this->errors = [];
        $this->initialised = [];
        $this->initialisationErrors = [];
        $this->initialising = [];

        $this->scanModules($this->paths->systemModules(), ModuleDescriptor::SCOPE_SYSTEM);
        $this->scanModules($this->paths->userModules(), ModuleDescriptor::SCOPE_USER);
    }

    public function initialise(): void
    {
        foreach ($this->modules as $descriptor) {
            if (!$descriptor->autoload()) {
                continue;
            }

            $this->initialiseModule($descriptor);
        }
    }

    /**
     * @return array<string, ModuleDescriptor>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    public function get(string $slug): ?ModuleDescriptor
    {
        return $this->modules[$slug] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function initialised(): array
    {
        return $this->initialised;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function initialisationErrors(): array
    {
        return $this->initialisationErrors;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $modules = [];
        foreach ($this->modules as $descriptor) {
            $modules[] = $descriptor->toArray();
        }

        return [
            'modules' => $modules,
            'errors' => $this->errors,
            'initialised' => $this->initialised,
            'initialisation_errors' => $this->initialisationErrors,
        ];
    }

    private function scanModules(string $root, string $scope): void
    {
        if (!\is_dir($root)) {
            return;
        }

        $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isDir()) {
                continue;
            }

            $slug = $fileinfo->getBasename();
            $path = $fileinfo->getPathname();

            try {
                $descriptor = $this->loadModule($slug, $path, $scope);
                $this->modules[$slug] = $descriptor;
            } catch (\Throwable $exception) {
                $message = \sprintf('Failed to load module "%s": %s', $slug, $exception->getMessage());
                $this->logger->warning($message, ['exception' => $exception]);
                $this->errors[] = $message;
            }
        }
    }

    private function initialiseModule(ModuleDescriptor $descriptor): void
    {
        $slug = $descriptor->slug();

        if (isset($this->initialised[$slug]) || isset($this->initialisationErrors[$slug])) {
            return;
        }

        if (isset($this->initialising[$slug])) {
            $this->recordInitialisationError($descriptor, 'Circular dependency detected.');

            return;
        }

        $initializer = $descriptor->initializer();
        if ($initializer === null) {
            $this->recordInitialisationError($descriptor, 'Module does not provide an initializer callable.');

            return;
        }

        $this->initialising[$slug] = true;

        try {
            $dependencyErrors = $this->ensureDependencies($descriptor);
            if ($dependencyErrors !== []) {
                $this->recordInitialisationError($descriptor, \implode(' | ', $dependencyErrors));

                return;
            }

            $this->loadModuleClasses($descriptor);

            $context = new ModuleContext(
                $descriptor,
                $this->container,
                $this->commands,
                $this->events,
                $this->paths,
                $this->logger,
                $descriptor->capabilities()
            );

            $initializer($context);

            $this->initialised[$slug] = [
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
                'scope' => $descriptor->scope(),
            ];

            $this->events->emit('module.initialized', [
                'slug' => $slug,
                'scope' => $descriptor->scope(),
                'version' => $descriptor->version(),
            ]);
        } catch (\Throwable $exception) {
            $this->recordInitialisationError($descriptor, $exception->getMessage(), $exception);
        } finally {
            unset($this->initialising[$slug]);
        }
    }

    private function loadModule(string $slug, string $path, string $scope): ModuleDescriptor
    {
        $manifest = $this->loadManifest($path);
        $definition = $this->loadDefinition($path, $manifest);

        $name = $this->resolveString('name', $definition, $manifest, $slug);
        $version = $this->resolveString('version', $definition, $manifest, '0.0.0');
        $autoload = $this->resolveBool('autoload', $definition, $manifest, true);
        $dependencies = $this->resolveArray('requires', $manifest, []);
        $capabilityCandidates = $this->resolveArray(
            'capabilities',
            $definition,
            $this->resolveArray('capabilities', $manifest, [])
        );
        $issues = [];

        $initializer = null;
        if (isset($definition['init']) && \is_callable($definition['init'])) {
            $initializer = $definition['init'];
        } else {
            $issues[] = 'Missing callable init handler.';
        }

        if (!isset($definition['commands']) && !isset($definition['init'])) {
            $issues[] = 'Module definition returned no actionable metadata.';
        }

        $capabilities = $this->normalizeCapabilities($scope, $capabilityCandidates, $issues);

        return new ModuleDescriptor(
            $slug,
            $name,
            $version,
            $scope,
            $path,
            $manifest,
            $definition,
            $dependencies,
            $autoload,
            $capabilities,
            $initializer,
            $issues
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(string $path): array
    {
        $file = $path . DIRECTORY_SEPARATOR . 'manifest.json';

        if (!\is_file($file)) {
            return [];
        }

        try {
            $json = \file_get_contents($file);
            if ($json === false) {
                throw new \RuntimeException('Unable to read file.');
            }

            $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $message = \sprintf('Invalid manifest for module at "%s": %s', $path, $exception->getMessage());
            $this->logger->warning($message, ['exception' => $exception]);
            $this->errors[] = $message;

            return [];
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function loadDefinition(string $path, array $manifest): array
    {
        $moduleFile = $path . DIRECTORY_SEPARATOR . 'module.php';

        if (!\is_file($moduleFile)) {
            $message = \sprintf('Module at "%s" does not contain module.php.', $path);
            $this->logger->warning($message);
            $this->errors[] = $message;

            return $manifest;
        }

        $definition = require $moduleFile;

        if (!\is_array($definition)) {
            $message = \sprintf('module.php at "%s" must return an array.', $path);
            $this->logger->warning($message);
            $this->errors[] = $message;

            return $manifest;
        }

        return \array_merge($manifest, $definition);
    }

    /**
     * @return array<int, string>
     */
    private function ensureDependencies(ModuleDescriptor $descriptor): array
    {
        $errors = [];

        foreach ($descriptor->dependencies() as $dependency) {
            $parsed = $this->normalizeDependencyString($dependency);
            if ($parsed === null) {
                $errors[] = \sprintf('Invalid dependency definition "%s".', $dependency);
                continue;
            }

            $dependencyDescriptor = $this->findModuleDescriptor($parsed['slug']);
            if ($dependencyDescriptor === null) {
                $errors[] = \sprintf('Dependency "%s" is not available.', $parsed['slug']);
                continue;
            }

            if ($dependencyDescriptor->slug() === $descriptor->slug()) {
                $errors[] = 'Module cannot depend on itself.';
                continue;
            }

            $this->initialiseModule($dependencyDescriptor);

            if (!isset($this->initialised[$dependencyDescriptor->slug()])) {
                $errors[] = \sprintf('Dependency "%s" failed to initialise.', $dependencyDescriptor->slug());
                continue;
            }

            if ($parsed['version'] !== null && $dependencyDescriptor->version() !== $parsed['version']) {
                $errors[] = \sprintf(
                    'Dependency "%s" requires version "%s" but "%s" is loaded.',
                    $dependencyDescriptor->slug(),
                    $parsed['version'],
                    $dependencyDescriptor->version()
                );
            }
        }

        return $errors;
    }

    private function normalizeDependencyString(string $dependency): ?array
    {
        $normalized = \trim($dependency);
        if ($normalized === '') {
            return null;
        }

        $version = null;
        if (\strpos($normalized, '@') !== false) {
            [$slug, $version] = \array_map('trim', \explode('@', $normalized, 2));
            if ($slug === '' || $version === '') {
                return null;
            }

            if (!\preg_match('/^[a-z0-9._-]+$/i', $slug)) {
                return null;
            }

            if (\preg_match('/[<>=*]/', $version)) {
                return null;
            }

            return [
                'slug' => $slug,
                'version' => $version,
            ];
        }

        if (!\preg_match('/^[a-z0-9._-]+$/i', $normalized)) {
            return null;
        }

        return [
            'slug' => $normalized,
            'version' => null,
        ];
    }

    private function findModuleDescriptor(string $slug): ?ModuleDescriptor
    {
        if (isset($this->modules[$slug])) {
            return $this->modules[$slug];
        }

        $lower = \strtolower($slug);
        foreach ($this->modules as $descriptor) {
            if (\strtolower($descriptor->slug()) === $lower) {
                return $descriptor;
            }
        }

        return null;
    }

    private function recordInitialisationError(ModuleDescriptor $descriptor, string $message, ?\Throwable $exception = null): void
    {
        $slug = $descriptor->slug();

        $payload = [
            'message' => $message,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        if ($exception !== null) {
            $payload['exception'] = [
                'type' => \get_class($exception),
                'message' => $exception->getMessage(),
            ];
        }

        $this->initialisationErrors[$slug] = $payload;

        $context = [
            'slug' => $slug,
            'scope' => $descriptor->scope(),
            'version' => $descriptor->version(),
            'reason' => $message,
        ];

        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $this->logger->error(\sprintf('Module "%s" initialisation failed: %s', $slug, $message), [
            'module' => $slug,
            'scope' => $descriptor->scope(),
            'version' => $descriptor->version(),
            'exception' => $exception,
        ]);

        $this->events->emit('module.initialization_failed', $context);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeCapabilities(string $scope, array $requested, array &$issues): array
    {
        $allowed = self::ALLOWED_CAPABILITIES[$scope] ?? [];
        $defaults = self::DEFAULT_CAPABILITIES[$scope] ?? [];
        $capabilities = $defaults;

        foreach ($requested as $entry) {
            if (\is_array($entry)) {
                $issues[] = 'Capability definition must be a string.';
                continue;
            }

            $capability = \strtolower(\trim((string) $entry));

            if ($capability === '') {
                continue;
            }

            if (!\in_array($capability, $allowed, true)) {
                $issues[] = \sprintf('Capability "%s" is not allowed for %s modules.', $capability, $scope);
                continue;
            }

            if (!\in_array($capability, $capabilities, true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    private function loadModuleClasses(ModuleDescriptor $descriptor): void
    {
        $classesDir = $descriptor->path() . DIRECTORY_SEPARATOR . 'classes';
        if (!\is_dir($classesDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $classesDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (\strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            require_once $file->getPathname();
        }
    }

    private function resolveString(string $key, array $primary, array $secondary, string $fallback): string
    {
        $value = $primary[$key] ?? $secondary[$key] ?? $fallback;

        return \is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function resolveBool(string $key, array $primary, array $secondary, bool $fallback): bool
    {
        $value = $primary[$key] ?? $secondary[$key] ?? $fallback;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
        }

        return $fallback;
    }

    /**
     * @return array<int, string>
     */
    private function resolveArray(string $key, array $source, array $fallback): array
    {
        $value = $source[$key] ?? $fallback;

        if (\is_array($value)) {
            return \array_values(array_map('strval', $value));
        }

        if (\is_string($value) && $value !== '') {
            return [trim($value)];
        }

        return $fallback;
    }
}
