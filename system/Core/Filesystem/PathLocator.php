<?php

declare(strict_types=1);

namespace AavionDB\Core\Filesystem;

use AavionDB\Core\Exceptions\StorageException;

/**
 * Resolves repository paths and ensures required directories exist.
 */
final class PathLocator
{
    private string $rootPath;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    public function __construct(string $rootPath, array $config = [])
    {
        $this->rootPath = \rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->config = $config;
    }

    public function root(): string
    {
        return $this->rootPath;
    }

    public function system(): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . 'system';
    }

    public function user(): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . 'user';
    }

    public function systemStorage(): string
    {
        return $this->system() . DIRECTORY_SEPARATOR . 'storage';
    }

    public function systemLogs(): string
    {
        $path = $this->config['log_path'] ?? 'system/storage/logs';

        return $this->resolvePath($path);
    }

    public function userStorage(): string
    {
        return $this->user() . DIRECTORY_SEPARATOR . 'storage';
    }

    public function systemModules(): string
    {
        return $this->system() . DIRECTORY_SEPARATOR . 'modules';
    }

    public function userModules(): string
    {
        return $this->user() . DIRECTORY_SEPARATOR . 'modules';
    }

    public function userBackups(): string
    {
        $path = $this->config['backups_path'] ?? 'user/backups';

        return $this->resolvePath($path);
    }

    public function userExports(): string
    {
        $path = $this->config['exports_path'] ?? 'user/exports';

        return $this->resolvePath($path);
    }

    public function systemBrain(): string
    {
        return $this->systemStorage() . DIRECTORY_SEPARATOR . 'system.brain';
    }

    public function userBrain(string $slug): string
    {
        $slug = $this->sanitizeSlug($slug);

        return $this->userStorage() . DIRECTORY_SEPARATOR . $slug . '.brain';
    }

    /**
     * Creates required directory structure if missing.
     */
    public function ensureDefaultDirectories(): void
    {
        $directories = [
            $this->systemStorage(),
            $this->systemLogs(),
            $this->systemModules(),
            $this->user(),
            $this->userStorage(),
            $this->userModules(),
            $this->userExports(),
            $this->user() . DIRECTORY_SEPARATOR . 'cache',
            $this->userBackups(),
        ];

        foreach ($directories as $directory) {
            $this->ensureDirectory($directory);
        }
    }

    /**
     * @throws StorageException
     */
    private function ensureDirectory(string $path): void
    {
        if (\is_dir($path)) {
            return;
        }

        if (!@\mkdir($path, 0775, true) && !\is_dir($path)) {
            throw new StorageException(sprintf('Unable to create directory "%s".', $path));
        }
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = \strtolower($slug);
        $slug = \preg_replace('/[^a-z0-9\-_.]/', '-', $slug) ?? $slug;
        $slug = \trim($slug, '-_.');

        return $slug === '' ? 'default' : $slug;
    }

    private function resolvePath(string $path): string
    {
        $path = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if ($path === '') {
            return $this->rootPath;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return (bool) \preg_match('/^[A-Za-z]:\\\\/', $path);
    }
}
