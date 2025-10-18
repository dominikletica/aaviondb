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

    private ?array $systemBrain = null;

    private ?string $activeBrainSlug = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $activeBrainData = null;

    private ?string $activeBrainPath = null;

    /**
     * @var array{last_write?: array<string, mixed>|null, last_failure?: array<string, mixed>|null}
     */
    private array $integrityState = [
        'last_write' => null,
        'last_failure' => null,
    ];

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
        $this->activeBrainPath = $path;
        $this->activeBrainData = null;

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
     * Returns metadata of all projects stored in the active brain.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listProjects(): array
    {
        $brain = $this->loadActiveBrain();
        $projects = $brain['projects'] ?? [];
        $result = [];

        foreach ($projects as $slug => $project) {
            if (!\is_array($project)) {
                continue;
            }

            $result[$slug] = [
                'slug' => $slug,
                'title' => $project['title'] ?? null,
                'created_at' => $project['created_at'] ?? null,
                'updated_at' => $project['updated_at'] ?? null,
                'entity_count' => isset($project['entities']) && \is_array($project['entities'])
                    ? \count($project['entities'])
                    : 0,
            ];
        }

        return $result;
    }

    /**
     * Returns metadata for all entities within a project.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listEntities(string $projectSlug): array
    {
        $project = $this->getProject($projectSlug);
        $entities = $project['entities'] ?? [];
        $result = [];

        foreach ($entities as $entitySlug => $entity) {
            if (!\is_array($entity) || !isset($entity['versions']) || !\is_array($entity['versions'])) {
                continue;
            }

            $result[$entitySlug] = [
                'slug' => $entitySlug,
                'active_version' => $entity['active_version'] ?? null,
                'version_count' => \count($entity['versions']),
            ];
        }

        return $result;
    }

    /**
     * Persists an entity payload as new version inside the active brain.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed> Commit metadata
     */
    public function saveEntity(string $projectSlug, string $entitySlug, array $payload, array $meta = []): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $slugEntity = $this->normalizeKey($entitySlug);

        $brain = $this->loadActiveBrain();
        $timestamp = $this->timestamp();

        if (!isset($brain['projects'][$slugProject])) {
            $brain['projects'][$slugProject] = $this->defaultProject($slugProject, $timestamp);
        }

        $project = &$brain['projects'][$slugProject];

        if (!isset($project['entities']) || !\is_array($project['entities'])) {
            $project['entities'] = [];
        }

        if (!isset($project['entities'][$slugEntity]) || !\is_array($project['entities'][$slugEntity])) {
            $project['entities'][$slugEntity] = [
                'slug' => $slugEntity,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'active_version' => null,
                'versions' => [],
            ];
        }

        $entity = &$project['entities'][$slugEntity];
        $currentVersion = $this->determineNextVersion($entity['versions'] ?? []);
        $hash = CanonicalJson::hash($payload);

        $commitData = [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => $currentVersion,
            'hash' => $hash,
            'payload' => $payload,
            'meta' => $meta,
            'timestamp' => $timestamp,
        ];

        $commitHash = CanonicalJson::hash($commitData);

        $record = [
            'version' => $currentVersion,
            'hash' => $hash,
            'commit' => $commitHash,
            'committed_at' => $timestamp,
            'status' => 'active',
            'payload' => $payload,
            'meta' => $meta,
        ];

        if (isset($entity['active_version'], $entity['versions'][$entity['active_version']])) {
            $entity['versions'][$entity['active_version']]['status'] = 'inactive';
        }

        $entity['versions'][(string) $currentVersion] = $record;
        $entity['active_version'] = (string) $currentVersion;
        $entity['updated_at'] = $timestamp;

        $project['updated_at'] = $timestamp;

        if (!isset($brain['commits']) || !\is_array($brain['commits'])) {
            $brain['commits'] = [];
        }

        $brain['commits'][$commitHash] = [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'hash' => $hash,
            'timestamp' => $timestamp,
        ];

        $brain['meta']['updated_at'] = $timestamp;
        $this->activeBrainData = $brain;

        $this->persistActiveBrain();

        $this->events->emit('brain.entity.saved', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'commit' => $commitHash,
        ]);

        return [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'hash' => $hash,
            'commit' => $commitHash,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Returns a specific entity version or the active version if no reference is supplied.
     *
     * @return array<string, mixed>
     */
    public function getEntityVersion(string $projectSlug, string $entitySlug, ?string $reference = null): array
    {
        $project = $this->getProject($projectSlug);
        $entity = $project['entities'][$this->normalizeKey($entitySlug)] ?? null;

        if (!\is_array($entity) || !isset($entity['versions'])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $versions = $entity['versions'];

        if ($reference === null) {
            $reference = $entity['active_version'] ?? null;
            if ($reference === null) {
                throw new StorageException('Entity does not have an active version.');
            }
        }

        // Allow lookup by commit hash.
        if (!isset($versions[$reference])) {
            $brain = $this->loadActiveBrain();
            if (isset($brain['commits'][$reference])) {
                $ref = $brain['commits'][$reference]['version'] ?? null;
                if ($ref !== null && isset($versions[$ref])) {
                    $reference = $ref;
                }
            }
        }

        if (!isset($versions[$reference])) {
            throw new StorageException(sprintf('Unknown entity reference "%s".', $reference));
        }

        return $versions[$reference];
    }

    /**
     * Returns a project array or throws.
     *
     * @return array<string, mixed>
     */
    public function getProject(string $projectSlug): array
    {
        $brain = $this->loadActiveBrain();
        $slug = $this->normalizeKey($projectSlug);

        if (!isset($brain['projects'][$slug]) || !\is_array($brain['projects'][$slug])) {
            throw new StorageException(sprintf('Project "%s" not found in active brain.', $projectSlug));
        }

        return $brain['projects'][$slug];
    }

    /**
     * Retrieves a configuration value from the active or system brain.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function getConfigValue(string $key, $default = null, bool $system = false)
    {
        $key = $this->normalizeConfigKey($key);
        $brain = $system ? $this->loadSystemBrain() : $this->loadActiveBrain();

        if (!isset($brain['config']) || !\is_array($brain['config'])) {
            return $default;
        }

        return $brain['config'][$key] ?? $default;
    }

    /**
     * Writes a configuration value to the active or system brain.
     *
     * @param mixed $value
     */
    public function setConfigValue(string $key, $value, bool $system = false): void
    {
        $key = $this->normalizeConfigKey($key);
        $timestamp = $this->timestamp();

        if ($system) {
            $brain = $this->loadSystemBrain();
            $brain['config'][$key] = $value;
            $brain['meta']['updated_at'] = $timestamp;
            $this->systemBrain = $brain;
            $this->writeBrain($this->paths->systemBrain(), $brain);

            return;
        }

        $brain = $this->loadActiveBrain();
        if (!isset($brain['config']) || !\is_array($brain['config'])) {
            $brain['config'] = [];
        }

        $brain['config'][$key] = $value;
        $brain['meta']['updated_at'] = $timestamp;
        $this->activeBrainData = $brain;
        $this->persistActiveBrain();
    }

    /**
     * Removes a configuration value.
     */
    public function deleteConfigValue(string $key, bool $system = false): void
    {
        $key = $this->normalizeConfigKey($key);
        $timestamp = $this->timestamp();

        if ($system) {
            $brain = $this->loadSystemBrain();
            if (isset($brain['config'][$key])) {
                unset($brain['config'][$key]);
                $brain['meta']['updated_at'] = $timestamp;
                $this->systemBrain = $brain;
                $this->writeBrain($this->paths->systemBrain(), $brain);
            }

            return;
        }

        $brain = $this->loadActiveBrain();
        if (isset($brain['config'][$key])) {
            unset($brain['config'][$key]);
            $brain['meta']['updated_at'] = $timestamp;
            $this->activeBrainData = $brain;
            $this->persistActiveBrain();
        }
    }

    /**
     * Returns all configuration entries.
     *
     * @return array<string, mixed>
     */
    public function listConfig(bool $system = false): array
    {
        $brain = $system ? $this->loadSystemBrain() : $this->loadActiveBrain();

        if (!isset($brain['config']) || !\is_array($brain['config'])) {
            return [];
        }

        return $brain['config'];
    }

    /**
     * Registers a new API token and returns its metadata alongside the plain token.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function registerAuthToken(string $token, array $metadata = []): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new StorageException('Auth token must not be empty.');
        }

        $hash = \hash('sha256', $token);
        $timestamp = $this->timestamp();
        $created = false;

        $entry = [
            'hash' => $hash,
            'status' => 'active',
            'created_at' => $metadata['created_at'] ?? $timestamp,
            'created_by' => $metadata['created_by'] ?? null,
            'token_preview' => $metadata['token_preview'] ?? $this->tokenPreview($token),
            'last_used_at' => null,
            'meta' => isset($metadata['meta']) && \is_array($metadata['meta']) ? $metadata['meta'] : [],
        ];

        if (isset($metadata['label'])) {
            $entry['label'] = (string) $metadata['label'];
        }

        if (isset($metadata['expires_at']) && \is_string($metadata['expires_at'])) {
            $entry['expires_at'] = $metadata['expires_at'];
        }

        $this->updateSystemBrain(function (array &$brain) use ($hash, &$entry, &$created, $timestamp): void {
            $brain['auth'] = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
            $brain['api'] = $this->mergeApiSection($brain['api'] ?? [], $this->defaultApiState());

            $existing = $brain['auth']['keys'][$hash] ?? null;
            if (\is_array($existing)) {
                // Preserve previous metadata that should survive re-registration.
                if (isset($existing['last_used_at'])) {
                    $entry['last_used_at'] = $existing['last_used_at'];
                }

                if (isset($existing['meta']) && \is_array($existing['meta']) && $entry['meta'] === []) {
                    $entry['meta'] = $existing['meta'];
                }

                if (isset($existing['label']) && !isset($entry['label'])) {
                    $entry['label'] = $existing['label'];
                }
            } else {
                $created = true;
            }

            $brain['auth']['keys'][$hash] = $entry;
            $brain['auth']['bootstrap_active'] = false;
            $brain['auth']['last_rotation_at'] = $timestamp;
        });

        $payload = $entry;
        $payload['token'] = $token;

        if ($created) {
            $this->events->emit('auth.key.created', [
                'hash' => $hash,
                'label' => $entry['label'] ?? null,
                'created_at' => $entry['created_at'],
            ]);
        } else {
            $this->events->emit('auth.key.updated', [
                'hash' => $hash,
                'label' => $entry['label'] ?? null,
            ]);
        }

        return $payload;
    }

    /**
     * Revokes an existing API token. Accepts plain token or sha256 hash.
     *
     * @param array<string, mixed> $metadata
     */
    public function revokeAuthToken(string $identifier, array $metadata = []): bool
    {
        $hash = $this->normalizeTokenHash($identifier);
        $timestamp = $this->timestamp();
        $revoked = false;

        $this->updateSystemBrain(function (array &$brain) use ($hash, $timestamp, $metadata, &$revoked): void {
            $brain['auth'] = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
            $brain['api'] = $this->mergeApiSection($brain['api'] ?? [], $this->defaultApiState());

            if (!isset($brain['auth']['keys'][$hash]) || !\is_array($brain['auth']['keys'][$hash])) {
                return;
            }

            $entry = $brain['auth']['keys'][$hash];
            $entry['hash'] = $hash;
            $entry['status'] = 'revoked';
            $entry['revoked_at'] = $timestamp;

            if (isset($metadata['revoked_by'])) {
                $entry['revoked_by'] = $metadata['revoked_by'];
            }

            if (isset($metadata['reason'])) {
                $entry['revoked_reason'] = $metadata['reason'];
            }

            $brain['auth']['keys'][$hash] = $entry;
            $revoked = true;

            $active = $this->countActiveAuthKeys($brain['auth']['keys']);
            if ($active === 0) {
                $brain['auth']['bootstrap_active'] = true;
                $brain['api']['enabled'] = false;
                $brain['api']['last_disabled_at'] = $timestamp;
            }
        });

        if ($revoked) {
            $this->events->emit('auth.key.revoked', [
                'hash' => $hash,
                'reason' => $metadata['reason'] ?? null,
            ]);
        }

        return $revoked;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAuthTokens(bool $includeRevoked = true): array
    {
        $state = $this->systemAuthState();
        $keys = $state['auth']['keys'] ?? [];

        $result = [];
        foreach ($keys as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if (!$includeRevoked && ($entry['status'] ?? 'active') !== 'active') {
                continue;
            }

            $result[] = $entry;
        }

        return $result;
    }

    public function setApiEnabled(bool $enabled, array $metadata = []): bool
    {
        $timestamp = $this->timestamp();
        $updated = false;

        $this->updateSystemBrain(function (array &$brain) use ($enabled, $metadata, $timestamp, &$updated): void {
            $brain['auth'] = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
            $brain['api'] = $this->mergeApiSection($brain['api'] ?? [], $this->defaultApiState());

            $active = $this->countActiveAuthKeys($brain['auth']['keys']);
            if ($enabled && $active === 0) {
                return;
            }

            if (($brain['api']['enabled'] ?? false) === $enabled) {
                $updated = true;
                return;
            }

            $brain['api']['enabled'] = $enabled;
            $brain['api']['last_request_at'] = $brain['api']['last_request_at'] ?? null;

            if ($enabled) {
                $brain['api']['last_enabled_at'] = $timestamp;
                $brain['auth']['bootstrap_active'] = false;
            } else {
                $brain['api']['last_disabled_at'] = $timestamp;
            }

            if (isset($metadata['actor'])) {
                $brain['api']['last_actor'] = $metadata['actor'];
            }

            if (isset($metadata['reason'])) {
                $brain['api']['last_reason'] = $metadata['reason'];
            }

            $updated = true;
        });

        if ($updated) {
            $this->events->emit('api.state.changed', [
                'enabled' => $enabled,
                'timestamp' => $timestamp,
                'reason' => $metadata['reason'] ?? null,
            ]);
        }

        return $updated;
    }

    public function isApiEnabled(): bool
    {
        $brain = $this->loadSystemBrain();

        return isset($brain['api']['enabled']) ? (bool) $brain['api']['enabled'] : false;
    }

    public function updateBootstrapKey(string $token, bool $active = true): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new StorageException('Bootstrap key must not be empty.');
        }

        $timestamp = $this->timestamp();

        $this->updateSystemBrain(function (array &$brain) use ($token, $active, $timestamp): void {
            $brain['auth'] = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
            $brain['auth']['bootstrap_key'] = $token;
            $brain['auth']['bootstrap_active'] = $active;
            $brain['auth']['last_rotation_at'] = $timestamp;
        });

        $this->events->emit('auth.bootstrap.updated', [
            'active' => $active,
        ]);
    }

    /**
     * Returns integrity telemetry for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function integrityReport(): array
    {
        $systemPath = $this->paths->systemBrain();
        $activeSlug = $this->activeBrain();
        $activePath = $activeSlug !== null ? $this->paths->userBrain($activeSlug) : null;
        $security = $this->authDiagnostics();

        return [
            'system_brain' => $this->describeFile($systemPath),
            'active_brain' => $activePath ? \array_merge($this->describeFile($activePath), ['slug' => $activeSlug]) : null,
            'state' => [
                'last_write' => $this->integrityState['last_write'] ?? null,
                'last_failure' => $this->integrityState['last_failure'] ?? null,
            ],
            'security' => $security,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function systemAuthState(): array
    {
        $brain = $this->loadSystemBrain();

        $auth = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
        $api = $this->mergeApiSection($brain['api'] ?? [], $this->defaultApiState());

        $active = 0;
        foreach ($auth['keys'] as $entry) {
            if (\is_array($entry) && ($entry['status'] ?? 'active') === 'active') {
                $active++;
            }
        }

        $authReturn = $auth;
        $authReturn['active_keys'] = $active;
        $authReturn['bootstrap_effective'] = $active === 0 || ($auth['bootstrap_active'] ?? false);

        $this->systemBrain = $brain;
        $this->systemBrain['auth'] = $auth;
        $this->systemBrain['api'] = $api;

        return [
            'auth' => $authReturn,
            'api' => $api,
        ];
    }

    public function touchAuthKey(string $hash, ?string $preview = null): void
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return;
        }

        $timestamp = $this->timestamp();

        $this->updateSystemBrain(function (array &$brain) use ($hash, $preview, $timestamp): void {
            if (!isset($brain['auth']) || !\is_array($brain['auth'])) {
                $brain['auth'] = $this->defaultAuthState();
            }

            if (!isset($brain['auth']['keys']) || !\is_array($brain['auth']['keys'])) {
                $brain['auth']['keys'] = [];
            }

            $updated = false;

            foreach ($brain['auth']['keys'] as $keyIdentifier => &$entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $entryHash = isset($entry['hash']) && \is_string($entry['hash'])
                    ? strtolower($entry['hash'])
                    : (\is_string($keyIdentifier) ? strtolower($keyIdentifier) : null);

                if ($entryHash !== $hash) {
                    continue;
                }

                $entry['hash'] = $hash;
                $entry['last_used_at'] = $timestamp;
                if ($preview !== null) {
                    $entry['token_preview'] = $preview;
                }

                $updated = true;
                $brain['auth']['bootstrap_active'] = false;

                if ($keyIdentifier !== $hash) {
                    $brain['auth']['keys'][$hash] = $entry;
                    unset($brain['auth']['keys'][$keyIdentifier]);
                }

                break;
            }
            unset($entry);

            if (!$updated) {
                return;
            }

            if (!isset($brain['api']) || !\is_array($brain['api'])) {
                $brain['api'] = $this->defaultApiState();
            }

            $brain['api']['last_request_at'] = $timestamp;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function authDiagnostics(): array
    {
        $state = $this->systemAuthState();
        $auth = $state['auth'];
        $api = $state['api'];

        $total = isset($auth['keys']) && \is_array($auth['keys']) ? \count($auth['keys']) : 0;
        $active = $auth['active_keys'] ?? 0;

        return [
            'api_enabled' => $api['enabled'] ?? false,
            'bootstrap_active' => $auth['bootstrap_effective'] ?? true,
            'total_keys' => $total,
            'active_keys' => $active,
            'last_request_at' => $api['last_request_at'] ?? null,
            'last_rotation_at' => $auth['last_rotation_at'] ?? null,
        ];
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
    private function writeBrain(string $path, array $data, int $attempt = 0): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new StorageException(sprintf('Unable to create directory "%s".', $directory));
        }

        $json = CanonicalJson::encode($data);
        $expectedHash = \hash('sha256', $json);
        $tmpPath = $this->writeAtomicTemporary($directory, \basename($path), $json);

        if (!@\chmod($tmpPath, 0664)) {
            // Non-fatal; permissions may vary depending on umask.
        }

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);
            throw new StorageException(sprintf('Unable to replace brain file "%s".', $path));
        }

        $verification = $this->verifyBrainIntegrity($path, $json, $expectedHash);

        if (!$verification['ok']) {
            $this->recordIntegrityFailure($path, $verification['reason'], $verification['context']);

            $context = array_merge(
                    [
                        'path' => $path,
                        'attempt' => $attempt + 1,
                        'expected_hash' => $expectedHash,
                    ],
                    $verification['context']
                );

            $this->events->emit('brain.write.retry', $context);

            if ($attempt >= 1) {
                throw new StorageException(sprintf(
                    'Integrity verification failed for "%s" after %d attempts.',
                    $path,
                    $attempt + 1
                ));
            }

            $this->writeBrain($path, $data, $attempt + 1);
        } else {
            $this->recordIntegritySuccess($path, $expectedHash, $attempt + 1);

            $this->events->emit('brain.write.completed', array_merge(
                [
                    'path' => $path,
                    'hash' => $expectedHash,
                    'attempts' => $attempt + 1,
                ],
                $verification['context']
            ));
        }
    }

    /**
     * @throws StorageException
     */
    private function writeAtomicTemporary(string $directory, string $baseName, string $contents): string
    {
        $tmpPath = \tempnam($directory, $baseName . '.tmp-');
        if ($tmpPath === false) {
            throw new StorageException(sprintf('Unable to create temporary file in "%s".', $directory));
        }

        $handle = @\fopen($tmpPath, 'wb');
        if ($handle === false) {
            @\unlink($tmpPath);
            throw new StorageException(sprintf('Unable to open temporary brain file "%s" for writing.', $tmpPath));
        }

        try {
            if (!@\flock($handle, LOCK_EX)) {
                throw new StorageException(sprintf('Unable to obtain exclusive lock on "%s".', $tmpPath));
            }

            $length = \strlen($contents);
            $written = 0;

            while ($written < $length) {
                $chunk = @\fwrite($handle, substr($contents, $written));
                if ($chunk === false) {
                    throw new StorageException(sprintf('Failed writing to temporary brain file "%s".', $tmpPath));
                }

                $written += $chunk;
            }

            if (!@\fflush($handle)) {
                throw new StorageException(sprintf('Unable to flush temporary brain file "%s".', $tmpPath));
            }

            @\flock($handle, LOCK_UN);
        } catch (\Throwable $exception) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            @\unlink($tmpPath);

            throw $exception;
        }

        if (!@\fclose($handle)) {
            @\unlink($tmpPath);
            throw new StorageException(sprintf('Unable to close temporary brain file "%s".', $tmpPath));
        }

        return $tmpPath;
    }

    private function verifyBrainIntegrity(string $path, string $expectedJson, string $expectedHash): array
    {
        \clearstatcache(true, $path);

        $content = @\file_get_contents($path);
        if ($content === false) {
            $payload = [
                'path' => $path,
                'reason' => 'read_failed',
                'expected_hash' => $expectedHash,
            ];
            $this->events->emit('brain.write.integrity_failed', $payload);

            return [
                'ok' => false,
                'reason' => 'read_failed',
                'context' => [
                    'expected_hash' => $expectedHash,
                ],
            ];
        }

        $actualHash = \hash('sha256', $content);
        if ($actualHash !== $expectedHash) {
            $payload = [
                'path' => $path,
                'reason' => 'hash_mismatch',
                'expected_hash' => $expectedHash,
                'actual_hash' => $actualHash,
            ];
            $this->events->emit('brain.write.integrity_failed', $payload);

            return [
                'ok' => false,
                'reason' => 'hash_mismatch',
                'context' => [
                    'expected_hash' => $expectedHash,
                    'actual_hash' => $actualHash,
                ],
            ];
        }

        if ($content !== $expectedJson) {
            $payload = [
                'path' => $path,
                'reason' => 'content_mismatch',
                'expected_hash' => $expectedHash,
                'actual_hash' => $actualHash,
            ];
            $this->events->emit('brain.write.integrity_failed', $payload);

            return [
                'ok' => false,
                'reason' => 'content_mismatch',
                'context' => [
                    'expected_hash' => $expectedHash,
                    'actual_hash' => $actualHash,
                ],
            ];
        }

        try {
            $decoded = \json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $reencoded = CanonicalJson::encode($decoded);
        } catch (\JsonException $exception) {
            $payload = [
                'path' => $path,
                'reason' => 'json_decode_error',
                'message' => $exception->getMessage(),
                'expected_hash' => $expectedHash,
            ];
            $this->events->emit('brain.write.integrity_failed', $payload);

            return [
                'ok' => false,
                'reason' => 'json_decode_error',
                'context' => [
                    'expected_hash' => $expectedHash,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($reencoded !== $expectedJson) {
            $payload = [
                'path' => $path,
                'reason' => 'canonical_mismatch',
                'expected_hash' => $expectedHash,
                'actual_hash' => $actualHash,
            ];
            $this->events->emit('brain.write.integrity_failed', $payload);

            return [
                'ok' => false,
                'reason' => 'canonical_mismatch',
                'context' => [
                    'expected_hash' => $expectedHash,
                    'actual_hash' => $actualHash,
                ],
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'context' => [
                'hash' => $expectedHash,
                'bytes' => \strlen($expectedJson),
            ],
        ];
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
            'projects' => [],
            'commits' => [],
            'config' => [],
            'auth' => $this->defaultAuthState($overrides['auth'] ?? []),
            'api' => $this->defaultApiState($overrides['api'] ?? []),
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
            'projects' => [],
            'commits' => [],
            'config' => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function defaultAuthState(array $overrides = []): array
    {
        return [
            'bootstrap_key' => $overrides['bootstrap_key'] ?? 'admin',
            'bootstrap_active' => $overrides['bootstrap_active'] ?? true,
            'keys' => [],
            'last_rotation_at' => $overrides['last_rotation_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function defaultApiState(array $overrides = []): array
    {
        return [
            'enabled' => $overrides['enabled'] ?? false,
            'last_enabled_at' => $overrides['last_enabled_at'] ?? null,
            'last_disabled_at' => $overrides['last_disabled_at'] ?? null,
            'last_request_at' => $overrides['last_request_at'] ?? null,
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

        if (!isset($merged['config']) || !\is_array($merged['config'])) {
            $merged['config'] = [];
        }

        $merged['auth'] = $this->mergeAuthSection(
            isset($merged['auth']) && \is_array($merged['auth']) ? $merged['auth'] : [],
            $defaults['auth']
        );

        $merged['api'] = $this->mergeApiSection(
            isset($merged['api']) && \is_array($merged['api']) ? $merged['api'] : [],
            $defaults['api']
        );

        return $merged;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function mergeAuthSection(array $current, array $defaults): array
    {
        $merged = $defaults;

        $merged['bootstrap_key'] = isset($current['bootstrap_key']) && \is_string($current['bootstrap_key']) && $current['bootstrap_key'] !== ''
            ? $current['bootstrap_key']
            : $defaults['bootstrap_key'];

        $merged['bootstrap_active'] = isset($current['bootstrap_active'])
            ? (bool) $current['bootstrap_active']
            : $defaults['bootstrap_active'];

        $merged['last_rotation_at'] = isset($current['last_rotation_at']) && \is_string($current['last_rotation_at'])
            ? $current['last_rotation_at']
            : $defaults['last_rotation_at'];

        $merged['keys'] = [];

        if (isset($current['keys']) && \is_array($current['keys'])) {
            foreach ($current['keys'] as $identifier => $entry) {
                $normalized = $this->normalizeAuthKeyEntry($identifier, $entry);
                if ($normalized !== null) {
                    $merged['keys'][$normalized['hash']] = $normalized;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function mergeApiSection(array $current, array $defaults): array
    {
        $merged = $defaults;

        $merged['enabled'] = isset($current['enabled'])
            ? (bool) $current['enabled']
            : $defaults['enabled'];

        $merged['last_enabled_at'] = isset($current['last_enabled_at']) && \is_string($current['last_enabled_at'])
            ? $current['last_enabled_at']
            : $defaults['last_enabled_at'];

        $merged['last_disabled_at'] = isset($current['last_disabled_at']) && \is_string($current['last_disabled_at'])
            ? $current['last_disabled_at']
            : $defaults['last_disabled_at'];

        $merged['last_request_at'] = isset($current['last_request_at']) && \is_string($current['last_request_at'])
            ? $current['last_request_at']
            : $defaults['last_request_at'];

        return $merged;
    }

    /**
     * @param mixed $entry
     *
     * @return array<string, mixed>|null
     */
    private function normalizeAuthKeyEntry($identifier, $entry): ?array
    {
        if (\is_string($entry) && $entry !== '') {
            $hash = \hash('sha256', $entry);

            return [
                'hash' => $hash,
                'status' => 'active',
                'created_at' => null,
                'created_by' => null,
                'token_preview' => $this->tokenPreview($entry),
            ];
        }

        if (!\is_array($entry)) {
            return null;
        }

        $hash = null;
        if (isset($entry['hash']) && \is_string($entry['hash']) && $entry['hash'] !== '') {
            $hash = strtolower($entry['hash']);
        } elseif (\is_string($identifier) && $identifier !== '') {
            $hash = strtolower($identifier);
        } elseif (isset($entry['token']) && \is_string($entry['token']) && $entry['token'] !== '') {
            $hash = \hash('sha256', $entry['token']);
        }

        if ($hash === null) {
            return null;
        }

        $status = isset($entry['status']) && \is_string($entry['status']) ? strtolower($entry['status']) : 'active';
        if (!\in_array($status, ['active', 'revoked'], true)) {
            $status = 'active';
        }

        $normalized = $entry;
        $normalized['hash'] = $hash;
        $normalized['status'] = $status;

        if (!isset($normalized['token_preview']) || !\is_string($normalized['token_preview'])) {
            $normalized['token_preview'] = isset($entry['token']) && \is_string($entry['token'])
                ? $this->tokenPreview($entry['token'])
                : ($normalized['token_preview'] ?? null);
        }

        unset($normalized['token']);

        return $normalized;
    }

    private function determineActiveBrainSlug(): string
    {
        $slug = $this->systemBrain['state']['active_brain'] ?? $this->options['active_brain'] ?? 'default';

        // Let PathLocator sanitise slug.
        $path = $this->paths->userBrain((string) $slug);
        $basename = \basename($path, '.brain');

        return $basename !== '' ? $basename : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadActiveBrain(): array
    {
        if ($this->activeBrainSlug === null) {
            $this->ensureActiveBrain();
        }

        if ($this->activeBrainData === null) {
            $this->activeBrainData = $this->readBrain($this->activeBrainPath ?? $this->paths->userBrain($this->activeBrainSlug ?? 'default'));
        }

        if (!isset($this->activeBrainData['config']) || !\is_array($this->activeBrainData['config'])) {
            $this->activeBrainData['config'] = [];
        }

        return $this->activeBrainData;
    }

    private function persistActiveBrain(): void
    {
        if ($this->activeBrainData === null || $this->activeBrainPath === null) {
            return;
        }

        $this->writeBrain($this->activeBrainPath, $this->activeBrainData);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSystemBrain(): array
    {
        if ($this->systemBrain === null) {
            $this->ensureSystemBrain();
        }

        if ($this->systemBrain === null) {
            $this->systemBrain = $this->defaultSystemBrain();
        }

        if (!isset($this->systemBrain['config']) || !\is_array($this->systemBrain['config'])) {
            $this->systemBrain['config'] = [];
        }

        return $this->systemBrain;
    }

    /**
     * @param array<string, mixed> $versions
     */
    private function determineNextVersion(array $versions): int
    {
        if ($versions === []) {
            return 1;
        }

        $numeric = [];
        foreach ($versions as $key => $_) {
            $numeric[] = (int) $key;
        }

        return \max($numeric) + 1;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\-_.]/', '-', $value) ?? $value;
        $value = trim($value, '-_.');

        return $value === '' ? 'default' : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultProject(string $slug, string $timestamp): array
    {
        return [
            'slug' => $slug,
            'title' => $slug,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'entities' => [],
        ];
    }

    private function recordIntegritySuccess(string $path, string $hash, int $attempts): void
    {
        $this->integrityState['last_write'] = [
            'path' => $path,
            'hash' => $hash,
            'attempts' => $attempts,
            'timestamp' => $this->timestamp(),
        ];

        $this->integrityState['last_failure'] = null;
    }

    private function recordIntegrityFailure(string $path, string $reason, array $context = []): void
    {
        $this->integrityState['last_failure'] = [
            'path' => $path,
            'reason' => $reason,
            'context' => $context,
            'timestamp' => $this->timestamp(),
        ];
    }

    /**
     * @param callable(array<string, mixed>): void $mutator
     */
    private function updateSystemBrain(callable $mutator): void
    {
        $brain = $this->loadSystemBrain();
        $original = $brain;

        $mutator($brain);

        if ($brain === $original) {
            return;
        }

        $brain['meta']['updated_at'] = $this->timestamp();
        $this->systemBrain = $brain;
        $this->writeBrain($this->paths->systemBrain(), $brain);
    }

    private function tokenPreview(string $token): string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return '';
        }

        $visible = substr($trimmed, 0, 4);

        return sprintf('%s...', $visible);
    }

    private function normalizeConfigKey(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new StorageException('Config key must not be empty.');
        }

        $normalized = preg_replace('/[^a-z0-9\-_.]/i', '_', $key) ?? $key;
        $normalized = trim(strtolower($normalized), '-_.');

        if ($normalized === '') {
            throw new StorageException(sprintf('Config key "%s" contains no valid characters.', $key));
        }

        return $normalized;
    }

    private function normalizeTokenHash(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new StorageException('Token identifier must not be empty.');
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $identifier) === 1) {
            return strtolower($identifier);
        }

        return strtolower(hash('sha256', $identifier));
    }

    /**
     * @param array<int|string, mixed> $keys
     */
    private function countActiveAuthKeys(array $keys): int
    {
        $count = 0;
        foreach ($keys as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (strtolower($entry['status'] ?? 'active') === 'active') {
                $count++;
            }
        }

        return $count;
    }


    private function describeFile(string $path): array
    {
        $exists = \is_file($path);
        $modifiedAt = null;
        $size = null;

        if ($exists) {
            $mtime = @\filemtime($path);
            if ($mtime !== false) {
                $modifiedAt = \date(DATE_ATOM, $mtime);
            }

            $fsize = @\filesize($path);
            if ($fsize !== false) {
                $size = $fsize;
            }
        }

        return [
            'path' => $path,
            'exists' => $exists,
            'size' => $size,
            'modified_at' => $modifiedAt,
        ];
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
