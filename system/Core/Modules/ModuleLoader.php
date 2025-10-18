<?php

declare(strict_types=1);

namespace AavionDB\Core\Modules;

use AavionDB\Core\CommandRegistry;
use AavionDB\Core\EventBus;
use AavionDB\Core\Filesystem\PathLocator;
use Psr\Log\LoggerInterface;

/**
 * Discovers and manages system and user modules.
 */
final class ModuleLoader
{
    private PathLocator $paths;

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

    public function __construct(
        PathLocator $paths,
        CommandRegistry $commands,
        EventBus $events,
        LoggerInterface $logger
    ) {
        $this->paths = $paths;
        $this->commands = $commands;
        $this->events = $events;
        $this->logger = $logger;
    }

    public function discover(): void
    {
        $this->modules = [];
        $this->errors = [];

        $this->scanModules($this->paths->systemModules(), ModuleDescriptor::SCOPE_SYSTEM);
        $this->scanModules($this->paths->userModules(), ModuleDescriptor::SCOPE_USER);
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

    private function loadModule(string $slug, string $path, string $scope): ModuleDescriptor
    {
        $manifest = $this->loadManifest($path);
        $definition = $this->loadDefinition($path, $manifest);

        $name = $this->resolveString('name', $definition, $manifest, $slug);
        $version = $this->resolveString('version', $definition, $manifest, '0.0.0');
        $autoload = $this->resolveBool('autoload', $definition, $manifest, true);
        $dependencies = $this->resolveArray('requires', $manifest, []);
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

