<?php

declare(strict_types=1);

namespace AavionDB\Core\Cache;

use AavionDB\Core\EventBus;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_map;
use function array_unique;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_replace;
use function strtolower;
use function time;
use function trim;

/**
 * File-based cache storage with event-driven invalidation.
 */
final class CacheManager
{
    private const FILE_EXTENSION = '.cache.json';

    private PathLocator $paths;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private string $directory;

    private bool $defaultsEnsured = false;

    private bool $listenersRegistered = false;

    public function __construct(PathLocator $paths, BrainRepository $brains, LoggerInterface $logger)
    {
        $this->paths = $paths;
        $this->brains = $brains;
        $this->logger = $logger;
        $this->directory = $paths->user() . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * Ensures default cache configuration keys exist in the system brain.
     */
    public function ensureDefaults(): void
    {
        if ($this->defaultsEnsured) {
            return;
        }

        $this->brains->ensureSystemBrain();

        $config = $this->brains->listConfig(true);

        if (!\array_key_exists('cache.active', $config)) {
            $this->brains->setConfigValue('cache.active', true, true);
        }

        if (!\array_key_exists('cache.ttl', $config)) {
            $this->brains->setConfigValue('cache.ttl', 300, true);
        }

        if (!is_dir($this->directory)) {
            @\mkdir($this->directory, 0775, true);
        }

        $this->defaultsEnsured = true;
    }

    /**
     * Registers event listeners that flush the cache whenever brain data changes.
     */
    public function registerEventListeners(EventBus $events): void
    {
        if ($this->listenersRegistered) {
            return;
        }

        $events->on('brain.write.completed', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.entity.saved', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.entity.deleted', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.entity.restored', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.project.updated', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.project.deleted', function (): void {
            $this->flush(['brain']);
        });

        $events->on('brain.cleanup.completed', function (): void {
            $this->flush(['brain']);
        });

        $this->listenersRegistered = true;
    }

    /**
     * Returns whether caching is currently enabled.
     */
    public function isEnabled(): bool
    {
        $value = $this->brains->getConfigValue('cache.active', true, true);

        return $this->toBool($value);
    }

    /**
     * Enables or disables the cache subsystem.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->brains->setConfigValue('cache.active', $enabled, true);
        if (!$enabled) {
            $this->flush();
        }
    }

    /**
     * Returns the default TTL (seconds).
     */
    public function ttl(): int
    {
        $value = $this->brains->getConfigValue('cache.ttl', 300, true);

        if (is_numeric($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : 300;
        }

        return 300;
    }

    /**
     * Updates the default TTL (seconds).
     */
    public function setTtl(int $seconds): void
    {
        $seconds = $seconds < 1 ? 60 : $seconds;
        $this->brains->setConfigValue('cache.ttl', $seconds, true);
    }

    /**
     * Returns cached value if available or null.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null, bool $allowInactive = false)
    {
        if (!$allowInactive && !$this->isEnabled()) {
            return $default;
        }

        $entry = $this->read($key);

        if ($entry === null) {
            return $default;
        }

        return $entry['value'];
    }

    /**
     * Stores a cache value.
     *
     * @param mixed $value
     * @param array<int, string> $tags
     */
    public function put(
        string $key,
        $value,
        ?int $ttl = null,
        array $tags = [],
        bool $allowInactive = false
    ): void {
        if (!$allowInactive && !$this->isEnabled()) {
            return;
        }

        $this->write($key, $value, $ttl, $tags);
    }

    /**
     * Returns cached value or computes and stores the result via callback.
     *
     * @param callable(): mixed $callback
     * @param array<int, string> $tags
     *
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = [])
    {
        if ($this->isEnabled()) {
            $entry = $this->read($key);
            if ($entry !== null) {
                return $entry['value'];
            }
        }

        $value = $callback();

        if ($this->isEnabled()) {
            $this->write($key, $value, $ttl, $tags);
        }

        return $value;
    }

    /**
     * Deletes a cached entry.
     */
    public function forget(string $key): void
    {
        $file = $this->fileForKey($key);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    /**
     * Flushes cache entries. When tags are provided, only matching entries are removed.
     *
     * @param array<int, string>|null $tags
     */
    public function flush(?array $tags = null): int
    {
        $this->ensureDirectory();
        $files = $this->allFiles();
        $removed = 0;

        foreach ($files as $file) {
            if ($tags !== null) {
                $entry = $this->decodeFile($file);
                if ($entry === null) {
                    continue;
                }

                $entryTags = $entry['tags'] ?? [];
                if (!\is_array($entryTags) || $entryTags === []) {
                    continue;
                }

                $intersection = \array_intersect($this->normaliseTags($tags), $this->normaliseTags($entryTags));
                if ($intersection === []) {
                    continue;
                }
            }

            if (@\unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Removes expired cache entries.
     */
    public function cleanupExpired(): int
    {
        $this->ensureDirectory();
        $files = $this->allFiles();
        $removed = 0;
        $now = time();

        foreach ($files as $file) {
            $entry = $this->decodeFile($file);
            if ($entry === null) {
                continue;
            }

            $expires = $entry['expires_at'] ?? null;
            if (!is_int($expires)) {
                continue;
            }

            if ($expires <= $now && @\unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Returns absolute cache directory path.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Reads cache entry metadata and value.
     *
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        $this->ensureDirectory();

        $file = $this->fileForKey($key);

        $entry = $this->decodeFile($file);
        if ($entry === null) {
            return null;
        }

        if (isset($entry['expires_at']) && is_int($entry['expires_at'])) {
            if ($entry['expires_at'] <= time()) {
                @\unlink($file);

                return null;
            }
        }

        return $entry;
    }

    /**
     * Encodes and stores cache entry data.
     *
     * @param mixed $value
     * @param array<int, string> $tags
     */
    private function write(string $key, $value, ?int $ttl, array $tags): void
    {
        $this->ensureDirectory();

        $ttl = $ttl !== null ? max(1, $ttl) : $this->ttl();
        $expiresAt = time() + $ttl;

        $payload = [
            'key' => $key,
            'created_at' => time(),
            'expires_at' => $expiresAt,
            'ttl' => $ttl,
            'tags' => $this->normaliseTags($tags),
            'value' => $value,
        ];

        try {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to encode cache payload.', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return;
        }

        if ($encoded === false) {
            $this->logger->warning('Failed to encode cache payload.', ['key' => $key]);

            return;
        }

        $file = $this->fileForKey($key);
        $tmp = $file . '.tmp';

        if (@\file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            $this->logger->warning('Unable to write cache file.', ['key' => $key, 'file' => $file]);
            @\unlink($tmp);

            return;
        }

        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);
            $this->logger->warning('Unable to replace cache file.', ['key' => $key, 'file' => $file]);
        }
    }

    /**
     * Normalises cache tags to lowercase unique values.
     *
     * @param array<int, string> $tags
     *
     * @return array<int, string>
     */
    private function normaliseTags(array $tags): array
    {
        $normalised = array_filter(array_map(static function ($tag) {
            if (!is_string($tag)) {
                return null;
            }

            $tag = trim(strtolower($tag));

            return $tag === '' ? null : $tag;
        }, $tags));

        return array_values(array_unique($normalised));
    }

    /**
     * Converts scalar config values to boolean.
     *
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (\is_string($value)) {
            $value = strtolower(trim($value));

            return \in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function allFiles(): array
    {
        $pattern = $this->directory . DIRECTORY_SEPARATOR . '*' . self::FILE_EXTENSION;

        $files = glob($pattern);
        if ($files === false) {
            return [];
        }

        return $files;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @\mkdir($this->directory, 0775, true);
        }
    }

    /**
     * Computes deterministic cache filename for key.
     */
    private function fileForKey(string $key): string
    {
        $sanitised = preg_replace('/[^a-z0-9\-_.]/i', '-', $key) ?? 'cache';
        $sanitised = trim($sanitised, '-_.');
        if ($sanitised === '') {
            $sanitised = 'cache';
        }

        $sanitised = substr($sanitised, 0, 48);
        $hash = hash('sha256', $key);

        return $this->directory . DIRECTORY_SEPARATOR . $sanitised . '-' . $hash . self::FILE_EXTENSION;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeFile(string $file): ?array
    {
        if (!\is_file($file)) {
            return null;
        }

        $raw = @\file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
