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

    public function __construct(string $rootPath)
    {
        $this->rootPath = \rtrim($rootPath, DIRECTORY_SEPARATOR);
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
        return $this->systemStorage() . DIRECTORY_SEPARATOR . 'logs';
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
            $this->user() . DIRECTORY_SEPARATOR . 'exports',
            $this->user() . DIRECTORY_SEPARATOR . 'cache',
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
}
