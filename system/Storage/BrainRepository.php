<?php

declare(strict_types=1);

namespace AavionDB\Storage;

use DateTimeImmutable;
use AavionDB\Core\EventBus;
use AavionDB\Core\Exceptions\StorageException;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Hashing\CanonicalJson;
use Ramsey\Uuid\Uuid;

/**
 * Manages lifecycle and persistence of system and user brain files.
 */
final class BrainRepository
{
    private PathLocator $paths;

    private EventBus $events;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $systemBrain = null;

    private ?string $activeBrainSlug = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(PathLocator $paths, EventBus $events, array $options = [])
    {
        $this->paths = $paths;
        $this->events = $events;
        $this->options = $options;
    }

    /**
     * Ensures that the system brain exists on disk and returns its contents.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function ensureSystemBrain(array $overrides = []): array
    {
        $path = $this->paths->systemBrain();

        if (!\file_exists($path)) {
            $brain = $this->defaultSystemBrain($overrides);
            $this->writeBrain($path, $brain);
        }

        $current = $this->readBrain($path);
        $normalized = $this->mergeSystemDefaults($current, $overrides);

        if ($normalized !== $current) {
            $this->writeBrain($path, $normalized);
        }

        $this->systemBrain = $normalized;

        return $this->systemBrain;
    }

    /**
     * Ensures that the configured active brain exists; returns its slug.
     */
    public function ensureActiveBrain(): string
    {
        if ($this->systemBrain === null) {
            $this->ensureSystemBrain();
        }

        $slug = $this->determineActiveBrainSlug();
        $path = $this->paths->userBrain($slug);

        if (!\file_exists($path)) {
            $brain = $this->defaultUserBrain($slug);
            $this->writeBrain($path, $brain);
            $this->events->emit('brain.created', ['slug' => $slug, 'type' => 'user']);
        }

        if (($this->systemBrain['state']['active_brain'] ?? null) !== $slug) {
            $this->systemBrain['state']['active_brain'] = $slug;
            $this->systemBrain['meta']['updated_at'] = $this->timestamp();
            $this->writeBrain($this->paths->systemBrain(), $this->systemBrain);
        }

        $this->activeBrainSlug = $slug;

        return $slug;
    }

    /**
     * Returns the currently active brain slug.
     */
    public function activeBrain(): ?string
    {
        return $this->activeBrainSlug ?? $this->systemBrain['state']['active_brain'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readBrain(string $path): array
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw new StorageException(sprintf('Unable to read brain file "%s".', $path));
        }

        $data = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new StorageException(sprintf('Brain file "%s" is malformed.', $path));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeBrain(string $path, array $data): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new StorageException(sprintf('Unable to create directory "%s".', $directory));
        }

        $json = CanonicalJson::encode($data);
        $tmpPath = $path . '.tmp';

        if (@\file_put_contents($tmpPath, $json) === false) {
            throw new StorageException(sprintf('Failed to write temporary brain file "%s".', $tmpPath));
        }

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);
            throw new StorageException(sprintf('Unable to replace brain file "%s".', $path));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function defaultSystemBrain(array $overrides = []): array
    {
        $timestamp = $this->timestamp();

        return [
            'meta' => [
                'slug' => 'system',
                'uuid' => $overrides['meta']['uuid'] ?? Uuid::uuid4()->toString(),
                'schema_version' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            'state' => [
                'active_brain' => $overrides['state']['active_brain'] ?? $this->options['active_brain'] ?? 'default',
            ],
            'projects' => new \stdClass(),
            'commits' => new \stdClass(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultUserBrain(string $slug): array
    {
        $timestamp = $this->timestamp();

        return [
            'meta' => [
                'slug' => $slug,
                'schema_version' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            'projects' => new \stdClass(),
            'commits' => new \stdClass(),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function mergeSystemDefaults(array $current, array $overrides): array
    {
        $defaults = $this->defaultSystemBrain($overrides);
        $merged = $defaults;

        foreach ($current as $key => $value) {
            if (\is_array($value) || \is_object($value)) {
                $merged[$key] = $value;
            } else {
                $merged[$key] = $value ?? $defaults[$key] ?? null;
            }
        }

        if (!isset($merged['state']['active_brain']) || !\is_string($merged['state']['active_brain'])) {
            $merged['state']['active_brain'] = $defaults['state']['active_brain'];
        }

        if (!isset($merged['meta']['uuid']) || !\is_string($merged['meta']['uuid']) || $merged['meta']['uuid'] === '') {
            // If uuid missing, we leave null; Bootstrap will provide, but ensure field exists.
            $merged['meta']['uuid'] = $defaults['meta']['uuid'];
        }

        return $merged;
    }

    private function determineActiveBrainSlug(): string
    {
        $slug = $this->systemBrain['state']['active_brain'] ?? $this->options['active_brain'] ?? 'default';

        // Let PathLocator sanitise slug.
        $path = $this->paths->userBrain((string) $slug);
        $basename = \basename($path, '.brain');

        return $basename !== '' ? $basename : 'default';
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
