<?php

declare(strict_types=1);

namespace AavionDB\Storage;

use DateTimeImmutable;
use AavionDB\AavionDB;
use AavionDB\Core\EventBus;
use AavionDB\Core\Exceptions\StorageException;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Hashing\CanonicalJson;
use AavionDB\Schema\SchemaException;
use AavionDB\Schema\SchemaValidator;
use Ramsey\Uuid\Uuid;
use function in_array;
use function strtolower;
use function trim;
use function array_values;
use function array_filter;
use function array_key_exists;
use function is_array;
use function is_string;
use function is_bool;
use function is_numeric;
use function sprintf;

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

    private ?SchemaValidator $schemaValidator = null;

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

            if (!$this->canReadProject($slug)) {
                continue;
            }

            $result[$slug] = $this->summarizeProject($slug, $project);
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
        $slug = $this->normalizeKey($projectSlug);
        $this->assertReadAllowed($slug);
        $project = $this->getProject($slug);
        $entities = $project['entities'] ?? [];
        $result = [];

        foreach ($entities as $entitySlug => $entity) {
            if (!\is_array($entity)) {
                continue;
            }

            $result[$entitySlug] = $this->summarizeEntity($entitySlug, $entity);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEntityVersions(string $projectSlug, string $entitySlug): array
    {
        $this->assertReadAllowed($projectSlug);
        $project = $this->getProject($projectSlug);
        $slug = $this->normalizeKey($entitySlug);

        if (!isset($project['entities'][$slug]) || !\is_array($project['entities'][$slug])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = $project['entities'][$slug];
        $versions = isset($entity['versions']) && \is_array($entity['versions']) ? $entity['versions'] : [];

        $result = [];
        foreach ($versions as $versionKey => $record) {
            if (!\is_array($record)) {
                continue;
            }

            $versionId = (string) ($record['version'] ?? $versionKey);
            $result[] = [
                'version' => $versionId,
                'status' => $record['status'] ?? 'inactive',
                'hash' => $record['hash'] ?? null,
                'commit' => $record['commit'] ?? null,
                'committed_at' => $record['committed_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Creates a new project within the active brain.
     */
    public function createProject(string $projectSlug, ?string $title = null, ?string $description = null): array
    {
        $slug = $this->normalizeKey($projectSlug);
        $brain = $this->loadActiveBrain();

        if (isset($brain['projects'][$slug])) {
            throw new StorageException(sprintf('Project "%s" already exists.', $projectSlug));
        }

        if (!$this->canWriteProject($slug)) {
            throw new StorageException(sprintf('Insufficient permissions to create project "%s".', $projectSlug));
        }

        $timestamp = $this->timestamp();
        $project = $this->defaultProject($slug, $timestamp);
        if ($title !== null && $title !== '') {
            $project['title'] = $title;
        }
        if ($description !== null && $description !== '') {
            $project['description'] = $description;
        }

        $brain['projects'][$slug] = $project;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.created', [
            'project' => $slug,
            'title' => $project['title'],
            'description' => $project['description'] ?? null,
        ]);

        return $this->projectReport($slug, false);
    }

    public function archiveProject(string $projectSlug): array
    {
        $slug = $this->normalizeKey($projectSlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slug])) {
            throw new StorageException(sprintf('Project "%s" does not exist.', $projectSlug));
        }

        $this->assertWriteAllowed($slug);

        $timestamp = $this->timestamp();
        $brain['projects'][$slug]['status'] = 'archived';
        $brain['projects'][$slug]['archived_at'] = $timestamp;
        $brain['projects'][$slug]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.archived', [
            'project' => $slug,
        ]);

        return $this->projectReport($slug, false);
    }

    public function deleteProject(string $projectSlug, bool $purgeCommits = true): void
    {
        $slug = $this->normalizeKey($projectSlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slug])) {
            throw new StorageException(sprintf('Project "%s" does not exist.', $projectSlug));
        }

        $this->assertWriteAllowed($slug);

        unset($brain['projects'][$slug]);

        if ($purgeCommits && isset($brain['commits']) && is_array($brain['commits'])) {
            foreach ($brain['commits'] as $hash => $commit) {
                if (!is_array($commit)) {
                    continue;
                }

                if (($commit['project'] ?? null) === $slug) {
                    unset($brain['commits'][$hash]);
                }
            }
        }

        $brain['meta']['updated_at'] = $this->timestamp();

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.deleted', [
            'project' => $slug,
            'purged_commits' => $purgeCommits,
        ]);
    }

    public function projectReport(string $projectSlug, bool $includeEntities = true): array
    {
        $slug = $this->normalizeKey($projectSlug);
        $this->assertReadAllowed($slug);
        $project = $this->getProject($slug);

        $summary = $this->summarizeProject($slug, $project);

        if ($includeEntities) {
            $summary['entities'] = \array_values($this->listEntities($slug));
        }

        return $summary;
    }

    /**
     * Updates project metadata (title/description) and returns the refreshed summary.
     *
     * @return array<string, mixed>
     */
    public function updateProjectMetadata(string $projectSlug, ?string $title = null, ?string $description = null): array
    {
        if ($title === null && $description === null) {
            return $this->projectReport($projectSlug, false);
        }

        $slug = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slug);

        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slug]) || !\is_array($brain['projects'][$slug])) {
            throw new StorageException(\sprintf('Project "%s" does not exist.', $projectSlug));
        }

        $project = &$brain['projects'][$slug];
        $changed = false;

        if ($title !== null) {
            $normalized = \trim($title);
            $project['title'] = $normalized === '' ? $slug : $normalized;
            $changed = true;
        }

        if ($description !== null) {
            $normalized = \trim($description);
            $project['description'] = $normalized === '' ? null : $normalized;
            $changed = true;
        }

        if (!$changed) {
            return $this->projectReport($slug, false);
        }

        $timestamp = $this->timestamp();
        $project['updated_at'] = $timestamp;
        $brain['projects'][$slug] = $project;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.updated', [
            'project' => $slug,
            'title' => $project['title'] ?? null,
            'description' => $project['description'] ?? null,
        ]);

        return $this->projectReport($slug, false);
    }

    public function entityReport(string $projectSlug, string $entitySlug, bool $includeVersions = true): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertReadAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);

        $project = $this->getProject($slugProject);

        if (!isset($project['entities'][$slugEntity]) || !\is_array($project['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = $project['entities'][$slugEntity];
        $summary = $this->summarizeEntity($slugEntity, $entity);

        if ($includeVersions) {
            $summary['versions'] = $this->listEntityVersions($slugProject, $slugEntity);
        }

        return $summary;
    }

    public function archiveEntity(string $projectSlug, string $entitySlug): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = &$brain['projects'][$slugProject]['entities'][$slugEntity];
        $timestamp = $this->timestamp();

        $entity['status'] = 'archived';
        $entity['archived_at'] = $timestamp;
        $entity['updated_at'] = $timestamp;

        if (isset($entity['active_version']) && isset($entity['versions'][$entity['active_version']])) {
            $entity['versions'][$entity['active_version']]['status'] = 'archived';
        }

        $entity['active_version'] = null;

        $brain['projects'][$slugProject]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.archived', [
            'project' => $slugProject,
            'entity' => $slugEntity,
        ]);

        return $this->entityReport($slugProject, $slugEntity, false);
    }

    public function deleteEntity(string $projectSlug, string $entitySlug, bool $purgeCommits = true): void
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        unset($brain['projects'][$slugProject]['entities'][$slugEntity]);

        if ($purgeCommits && isset($brain['commits']) && \is_array($brain['commits'])) {
            foreach ($brain['commits'] as $hash => $commit) {
                if (!\is_array($commit)) {
                    continue;
                }

                if (($commit['project'] ?? null) === $slugProject && ($commit['entity'] ?? null) === $slugEntity) {
                    unset($brain['commits'][$hash]);
                }
            }
        }

        $timestamp = $this->timestamp();
        $brain['projects'][$slugProject]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.deleted', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'purged_commits' => $purgeCommits,
        ]);
    }

    public function restoreEntityVersion(string $projectSlug, string $entitySlug, string $reference): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = &$brain['projects'][$slugProject]['entities'][$slugEntity];
        $versionKey = $this->resolveEntityVersionKey($brain, $slugProject, $slugEntity, $entity, $reference);

        if ($versionKey === null) {
            throw new StorageException(sprintf('Unknown entity reference "%s".', $reference));
        }

        foreach ($entity['versions'] as $key => &$record) {
            if (!\is_array($record)) {
                continue;
            }

            $record['status'] = ($key === $versionKey) ? 'active' : 'inactive';
        }
        unset($record);

        $entity['active_version'] = $versionKey;
        $entity['status'] = 'active';
        $entity['archived_at'] = null;
        $entity['updated_at'] = $this->timestamp();

        $brain['projects'][$slugProject]['updated_at'] = $entity['updated_at'];
        $brain['meta']['updated_at'] = $entity['updated_at'];

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.restored', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => $versionKey,
        ]);

        return $this->entityReport($slugProject, $slugEntity, true);
    }

    /**
     * Persists an entity payload as new version inside the active brain.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed> Commit metadata
     */
    public function saveEntity(string $projectSlug, string $entitySlug, array $payload, array $meta = [], array $options = []): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $slugEntity = $this->normalizeKey($entitySlug);

        $this->assertWriteAllowed($slugProject);

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
                'status' => 'active',
                'archived_at' => null,
                'fieldset' => null,
                'versions' => [],
            ];
        }

        $entity = &$project['entities'][$slugEntity];
        $entity['status'] = 'active';
        $entity['archived_at'] = null;

        $mergePayload = $this->extractMergeOption($options['merge'] ?? null);
        $sourceReference = $this->normalizeReference($options['source_reference'] ?? null);
        $fieldsetProvided = ($options['fieldset_provided'] ?? false) === true;
        $fieldsetReference = $fieldsetProvided ? $this->normalizeReference($options['fieldset_reference'] ?? null) : null;
        $fieldsetSlugOption = $fieldsetProvided ? ($options['fieldset'] ?? null) : null;

        $currentPayload = null;
        if ($mergePayload) {
            if ($sourceReference !== null) {
                try {
                    $sourceVersion = $this->getEntityVersion($projectSlug, $entitySlug, $sourceReference);
                } catch (StorageException $exception) {
                    throw new StorageException(sprintf('Merge source "%s" not found for entity "%s" in project "%s".', $sourceReference, $entitySlug, $projectSlug), 0, $exception);
                }

                $sourcePayload = $sourceVersion['payload'] ?? null;
                if (!is_array($sourcePayload)) {
                    throw new StorageException(sprintf('Merge source "%s" payload is invalid.', $sourceReference));
                }

                $currentPayload = $sourcePayload;
            } elseif (isset($entity['active_version'], $entity['versions'][$entity['active_version']]['payload']) && \is_array($entity['versions'][$entity['active_version']]['payload'])) {
                $currentPayload = $entity['versions'][$entity['active_version']]['payload'];
            }
        }

        $mergedPayload = $this->mergeEntityPayload($currentPayload, $payload, $mergePayload);

        if ($slugProject === 'fieldsets') {
            try {
                $this->schemaValidator()->assertValidSchema($mergedPayload);
            } catch (SchemaException $exception) {
                throw new StorageException(sprintf('Schema definition for "%s" is invalid: %s', $slugEntity, $exception->getMessage()), 0, $exception);
            }
            $entity['fieldset'] = null;
            $fieldsetReference = null;
        } else {
            $desiredFieldset = $entity['fieldset'] ?? null;

            if ($fieldsetProvided) {
                if ($fieldsetSlugOption === null) {
                    $desiredFieldset = null;
                    $fieldsetReference = null;
                } else {
                    $candidate = trim((string) $fieldsetSlugOption);
                    if ($candidate === '') {
                        $desiredFieldset = null;
                        $fieldsetReference = null;
                    } else {
                        $desiredFieldset = $this->normalizeKey($candidate);
                    }
                }
            }

            if (is_string($desiredFieldset) && $desiredFieldset !== '') {
                $schemaPayload = $this->resolveSchemaPayload($desiredFieldset, $fieldsetReference);
                try {
                    $mergedPayload = $this->schemaValidator()->applySchema($mergedPayload, $schemaPayload);
                } catch (SchemaException $exception) {
                    throw new StorageException(sprintf('Payload for entity "%s" violates schema "%s": %s', $slugEntity, $desiredFieldset, $exception->getMessage()), 0, $exception);
                }

                $entity['fieldset'] = $desiredFieldset;
            } else {
                $entity['fieldset'] = null;
                $fieldsetReference = null;
            }
        }

        $currentVersion = $this->determineNextVersion($entity['versions'] ?? []);
        $hash = CanonicalJson::hash($mergedPayload);

        $commitData = [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => $currentVersion,
            'hash' => $hash,
            'payload' => $mergedPayload,
            'meta' => $meta,
            'timestamp' => $timestamp,
            'merge' => $mergePayload,
            'fieldset' => $entity['fieldset'] ?? null,
        ];

        if ($sourceReference !== null) {
            $commitData['source_reference'] = $sourceReference;
        }

        if ($fieldsetReference !== null) {
            $commitData['fieldset_reference'] = $fieldsetReference;
        }

        $commitHash = CanonicalJson::hash($commitData);

        $record = [
            'version' => $currentVersion,
            'hash' => $hash,
            'commit' => $commitHash,
            'committed_at' => $timestamp,
            'status' => 'active',
            'payload' => $mergedPayload,
            'meta' => $meta,
            'merge' => $mergePayload,
        ];

        if ($sourceReference !== null) {
            $record['source_reference'] = $sourceReference;
        }

        if ($fieldsetReference !== null) {
            $record['fieldset_reference'] = $fieldsetReference;
        }

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
            'merge' => $mergePayload,
            'fieldset' => $entity['fieldset'] ?? null,
        ];

        if ($sourceReference !== null) {
            $brain['commits'][$commitHash]['source_reference'] = $sourceReference;
        }

        if ($fieldsetReference !== null) {
            $brain['commits'][$commitHash]['fieldset_reference'] = $fieldsetReference;
        }

        $brain['meta']['updated_at'] = $timestamp;
        $this->activeBrainData = $brain;

        $this->persistActiveBrain();

        $this->events->emit('brain.entity.saved', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'commit' => $commitHash,
            'merge' => $mergePayload,
            'fieldset' => $entity['fieldset'] ?? null,
            'source_reference' => $sourceReference,
            'fieldset_reference' => $fieldsetReference,
        ]);

        return [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'hash' => $hash,
            'commit' => $commitHash,
            'timestamp' => $timestamp,
            'merge' => $mergePayload,
            'fieldset' => $entity['fieldset'] ?? null,
            'source_reference' => $sourceReference,
            'fieldset_reference' => $fieldsetReference,
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

        $this->assertReadAllowed($slug);

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
     * @return array<int, array<string, mixed>>
     */
    public function listBrains(): array
    {
        $this->ensureSystemBrain();

        $activeSlug = $this->activeBrain() ?? $this->determineActiveBrainSlug();

        $brains = [];
        $brains[] = $this->describeBrainFile(
            'system',
            $this->paths->systemBrain(),
            $activeSlug === 'system',
            'system'
        );

        $storage = $this->paths->userStorage();
        if (\is_dir($storage)) {
            $files = \glob($storage . DIRECTORY_SEPARATOR . '*.brain') ?: [];
            \sort($files);

            foreach ($files as $file) {
                $slug = \basename($file, '.brain');
                $brains[] = $this->describeBrainFile($slug, $file, $slug === $activeSlug, 'user');
            }
        }

        return $brains;
    }

    /**
     * Creates a new user brain; optionally activates it.
     *
     * @return array<string, mixed>
     */
    public function createBrain(string $slug, bool $activate = false): array
    {
        $slug = $this->sanitizeBrainSlug($slug);
        $path = $this->paths->userBrain($slug);

        if (\is_file($path)) {
            throw new StorageException(\sprintf('Brain "%s" already exists.', $slug));
        }

        $brain = $this->defaultUserBrain($slug);
        $this->writeBrain($path, $brain);
        $this->events->emit('brain.created', ['slug' => $slug, 'type' => 'user']);

        if ($activate) {
            $this->setActiveBrain($slug);
        }

        return $this->describeBrainFile($slug, $path, $activate, 'user');
    }

    /**
     * Switches the active brain.
     */
    public function setActiveBrain(string $slug): array
    {
        $slug = $this->sanitizeBrainSlug($slug);

        if ($slug === 'system') {
            throw new StorageException('System brain cannot be the active user brain.');
        }

        $path = $this->paths->userBrain($slug);
        if (!\is_file($path)) {
            throw new StorageException(\sprintf('Brain "%s" does not exist.', $slug));
        }

        $this->updateSystemBrain(function (array &$brain) use ($slug): void {
            $brain['state']['active_brain'] = $slug;
        });

        $this->activeBrainSlug = $slug;
        $this->activeBrainPath = $path;
        $this->activeBrainData = null;

        return $this->describeBrainFile($slug, $path, true, 'user');
    }

    /**
     * Creates a snapshot copy of the specified brain (or active brain if null).
     *
     * @return array<string, mixed>
     */
    public function backupBrain(?string $slug = null, ?string $label = null): array
    {
        if ($slug === null || $slug === '') {
            $slug = $this->activeBrain() ?? $this->determineActiveBrainSlug();
        }

        $slug = $this->sanitizeBrainSlug($slug);
        $isSystem = $slug === 'system';

        $source = $isSystem ? $this->paths->systemBrain() : $this->paths->userBrain($slug);

        if (!\is_file($source)) {
            throw new StorageException(\sprintf('Brain "%s" does not exist.', $slug));
        }

        $labelPart = '';
        if ($label !== null && $label !== '') {
            $labelPart = '-' . $this->sanitizeBackupLabel($label);
        }

        $timestamp = (new DateTimeImmutable())->format('Ymd_His');
        $destination = $this->paths->userBackups() . DIRECTORY_SEPARATOR . \sprintf('%s%s-%s.brain', $slug, $labelPart, $timestamp);

        if (!@\copy($source, $destination)) {
            throw new StorageException('Unable to create brain backup.');
        }

        $bytes = @\filesize($destination) ?: null;

        $this->events->emit('brain.backup.created', [
            'slug' => $slug,
            'path' => $destination,
            'bytes' => $bytes,
            'is_system' => $isSystem,
        ]);

        return [
            'slug' => $slug,
            'path' => $destination,
            'bytes' => $bytes,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function brainReport(?string $slug = null): array
    {
        if ($slug === null || $slug === '') {
            $slug = $this->activeBrain() ?? $this->determineActiveBrainSlug();
        }

        $slug = $this->sanitizeBrainSlug($slug);

        if ($slug === 'system') {
            return $this->describeBrainFile('system', $this->paths->systemBrain(), true, 'system');
        }

        $path = $this->paths->userBrain($slug);

        return $this->describeBrainFile($slug, $path, $slug === ($this->activeBrain() ?? $this->determineActiveBrainSlug()), 'user');
    }

    /**
     * @return array<string, mixed>
     */
    public function integrityReportFor(?string $slug = null): array
    {
        if ($slug === null || $slug === '') {
            $slug = $this->activeBrain() ?? $this->determineActiveBrainSlug();
        }

        $slug = $this->sanitizeBrainSlug($slug);

        if ($slug === 'system') {
            return $this->integrityReport();
        }

        $path = $this->paths->userBrain($slug);
        if (!\is_file($path)) {
            throw new StorageException(\sprintf('Brain "%s" does not exist.', $slug));
        }

        return [
            'brain' => $this->describeBrainFile(
                $slug,
                $path,
                $slug === ($this->activeBrain() ?? $this->determineActiveBrainSlug()),
                'user'
            ),
            'hash' => @hash_file('sha256', $path) ?: null,
            'state' => [
                'last_write' => $this->integrityState['last_write'] ?? null,
                'last_failure' => $this->integrityState['last_failure'] ?? null,
            ],
        ];
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

        $scope = isset($metadata['scope']) ? strtoupper((string) $metadata['scope']) : null;
        $projects = $metadata['projects'] ?? null;

        if ($projects !== null && !\is_array($projects)) {
            $projects = array_map('trim', explode(',', (string) $projects));
        }

        if (\is_array($projects)) {
            $projects = array_values(array_filter(array_map('strtolower', $projects), static fn ($value) => $value !== ''));
        }

        if ($projects === null || $projects === []) {
            $projects = ['*'];
        }

        $entry = [
            'hash' => $hash,
            'status' => 'active',
            'created_at' => $metadata['created_at'] ?? $timestamp,
            'created_by' => $metadata['created_by'] ?? null,
            'token_preview' => $metadata['token_preview'] ?? $this->tokenPreview($token),
            'last_used_at' => null,
            'meta' => isset($metadata['meta']) && \is_array($metadata['meta']) ? $metadata['meta'] : [],
        ];

        if ($scope !== null) {
            $entry['meta']['scope'] = $scope;
        }

        $entry['meta']['projects'] = $projects;

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

                if (isset($existing['meta']) && \is_array($existing['meta'])) {
                    $entry['meta'] = \array_merge($existing['meta'], $entry['meta']);
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
     * Revokes all tokens and disables the REST API.
     */
    public function resetAuthTokens(): array
    {
        $timestamp = $this->timestamp();
        $revoked = 0;

        $this->updateSystemBrain(function (array &$brain) use ($timestamp, &$revoked): void {
            $brain['auth'] = $this->mergeAuthSection($brain['auth'] ?? [], $this->defaultAuthState());
            $brain['api'] = $this->mergeApiSection($brain['api'] ?? [], $this->defaultApiState());

            if (!isset($brain['auth']['keys']) || !\is_array($brain['auth']['keys'])) {
                $brain['auth']['keys'] = [];
            }

            foreach ($brain['auth']['keys'] as $hash => &$entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                if (($entry['status'] ?? 'active') === 'revoked') {
                    continue;
                }

                $entry['status'] = 'revoked';
                $entry['revoked_at'] = $timestamp;
                $entry['revoked_by'] = 'auth reset';
                $revoked++;
            }
            unset($entry);

            $brain['auth']['bootstrap_active'] = true;
            $brain['auth']['last_rotation_at'] = $timestamp;
        });

        $this->setApiEnabled(false, [
            'actor' => 'auth reset',
            'reason' => 'all tokens revoked',
        ]);

        $this->events->emit('auth.reset', [
            'revoked' => $revoked,
        ]);

        return [
            'revoked' => $revoked,
            'api_enabled' => false,
        ];
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

    private function assertReadAllowed(string $projectSlug): void
    {
        if (!$this->canReadProject($projectSlug)) {
            throw new StorageException(sprintf('Read access to project "%s" is not permitted for the current scope.', $projectSlug));
        }
    }

    private function assertWriteAllowed(string $projectSlug): void
    {
        if (!$this->canWriteProject($projectSlug)) {
            throw new StorageException(sprintf('Write access to project "%s" is not permitted for the current scope.', $projectSlug));
        }
    }

    private function canReadProject(string $projectSlug): bool
    {
        $mode = $this->scopeMode();

        if (!\in_array($mode, ['ALL', 'RW', 'RO', 'WO'], true)) {
            return false;
        }

        return $this->projectInScope($projectSlug);
    }

    private function canWriteProject(string $projectSlug): bool
    {
        $mode = $this->scopeMode();

        if (!\in_array($mode, ['ALL', 'RW', 'WO'], true)) {
            return false;
        }

        return $this->projectInScope($projectSlug);
    }

    /**
     * @return array{mode: string, projects: array<int, string>}
     */
    private function currentScope(): array
    {
        $scope = AavionDB::scope();

        $mode = isset($scope['mode']) && \is_string($scope['mode']) ? $scope['mode'] : 'ALL';
        $projects = $scope['projects'] ?? ['*'];

        if (!\is_array($projects)) {
            $projects = [$projects];
        }

        return [
            'mode' => \strtoupper($mode),
            'projects' => $projects,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function scopeProjects(): array
    {
        $scope = $this->currentScope();
        $projects = $scope['projects'];

        $normalized = [];

        foreach ($projects as $project) {
            if (!\is_string($project)) {
                continue;
            }

            $project = \trim($project);

            if ($project === '') {
                continue;
            }

            if ($project === '*') {
                return ['*'];
            }

            $normalized[] = $this->normalizeKey($project);
        }

        if ($normalized === []) {
            return ['*'];
        }

        return \array_values(\array_unique($normalized));
    }

    private function projectInScope(string $project): bool
    {
        $projects = $this->scopeProjects();

        if (\in_array('*', $projects, true)) {
            return true;
        }

        $slug = $this->normalizeKey($project);

        return \in_array($slug, $projects, true);
    }

    private function scopeMode(): string
    {
        $scope = $this->currentScope();
        $mode = \strtoupper($scope['mode'] ?? 'ALL');

        return \in_array($mode, ['ALL', 'RW', 'RO', 'WO'], true) ? $mode : 'ALL';
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
            'description' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'status' => 'active',
            'archived_at' => null,
            'entities' => [],
        ];
    }

    private function summarizeProject(string $slug, array $project): array
    {
        $entities = isset($project['entities']) && \is_array($project['entities']) ? $project['entities'] : [];
        $entityCount = \count($entities);
        $versionCount = 0;

        foreach ($entities as $entity) {
            if (!\is_array($entity) || !isset($entity['versions']) || !\is_array($entity['versions'])) {
                continue;
            }

            $versionCount += \count($entity['versions']);
        }

        return [
            'slug' => $slug,
            'title' => $project['title'] ?? null,
            'description' => $project['description'] ?? null,
            'status' => $project['status'] ?? 'active',
            'created_at' => $project['created_at'] ?? null,
            'updated_at' => $project['updated_at'] ?? null,
            'archived_at' => $project['archived_at'] ?? null,
            'entity_count' => $entityCount,
            'version_count' => $versionCount,
        ];
    }

    private function summarizeEntity(string $slug, array $entity): array
    {
        $versions = isset($entity['versions']) && \is_array($entity['versions']) ? $entity['versions'] : [];

        return [
            'slug' => $slug,
            'status' => $entity['status'] ?? 'active',
            'created_at' => $entity['created_at'] ?? null,
            'updated_at' => $entity['updated_at'] ?? null,
            'archived_at' => $entity['archived_at'] ?? null,
            'active_version' => $entity['active_version'] ?? null,
            'fieldset' => $entity['fieldset'] ?? null,
            'version_count' => \count($versions),
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

    private function schemaValidator(): SchemaValidator
    {
        if ($this->schemaValidator === null) {
            $this->schemaValidator = new SchemaValidator();
        }

        return $this->schemaValidator;
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $updates
     *
     * @return array<string, mixed>
     */
    private function mergeEntityPayload(?array $current, array $updates, bool $merge): array
    {
        if (!$merge || $current === null) {
            return $updates;
        }

        $result = $current;

        foreach ($updates as $key => $value) {
            if ($value === null) {
                if (array_key_exists($key, $result)) {
                    unset($result[$key]);
                }
                continue;
            }

            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $existing = array_key_exists($key, $result) && is_array($result[$key]) ? $result[$key] : [];
                    if (!$this->isAssociativeArray($existing)) {
                        $existing = [];
                    }

                    $merged = $this->mergeEntityPayload($existing, $value, true);
                    if ($merged === []) {
                        unset($result[$key]);
                        continue;
                    }

                    $result[$key] = $merged;
                    continue;
                }

                $result[$key] = $value;
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function extractMergeOption(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'replace') {
                return false;
            }

            return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'merge'], true);
        }

        return true;
    }

    private function normalizeReference(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSchemaPayload(string $fieldset, ?string $reference = null): array
    {
        try {
            $record = $this->getEntityVersion('fieldsets', $fieldset, $reference);
        } catch (StorageException $exception) {
            if ($reference !== null) {
                throw new StorageException(sprintf('Schema "%s" (reference %s) not found in project "fieldsets".', $fieldset, $reference), 0, $exception);
            }

            throw new StorageException(sprintf('Schema "%s" not found in project "fieldsets".', $fieldset), 0, $exception);
        }

        $payload = $record['payload'] ?? null;
        if (!is_array($payload)) {
            throw new StorageException(sprintf('Schema "%s" contains an invalid payload.', $fieldset));
        }

        try {
            $this->schemaValidator()->assertValidSchema($payload);
        } catch (SchemaException $exception) {
            throw new StorageException(sprintf('Schema "%s" is invalid: %s', $fieldset, $exception->getMessage()), 0, $exception);
        }

        return $payload;
    }

    private function isAssociativeArray(array $value): bool
    {
        $expected = 0;

        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return true;
            }

            $expected++;
        }

        return false;
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

    private function describeBrainFile(string $slug, string $path, bool $active, string $type): array
    {
        $exists = is_file($path);
        $size = $exists ? (@filesize($path) ?: null) : null;
        $modified = $exists ? (@filemtime($path) ?: null) : null;
        $modifiedAt = $modified ? date(DATE_ATOM, $modified) : null;
        $entityVersions = $exists ? $this->countEntityVersionsInFile($path) : null;

        return [
            'slug' => $slug,
            'type' => $type,
            'path' => $path,
            'exists' => $exists,
            'active' => $active,
            'size_bytes' => $size,
            'modified_at' => $modifiedAt,
            'entity_versions' => $entityVersions,
        ];
    }

    private function sanitizeBrainSlug(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '' || strtolower($slug) === 'system') {
            return strtolower($slug) === 'system' ? 'system' : $this->determineActiveBrainSlug();
        }

        return $this->normalizeKey($slug);
    }

    private function sanitizeBackupLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9\-_.]/', '-', $label) ?? $label;

        return trim($label, '-_.');
    }

    private function countEntityVersionsInFile(string $path): ?int
    {
        try {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return null;
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['projects']) || !is_array($decoded['projects'])) {
            return null;
        }

        $count = 0;
        foreach ($decoded['projects'] as $project) {
            if (!is_array($project) || !isset($project['entities']) || !is_array($project['entities'])) {
                continue;
            }

            foreach ($project['entities'] as $entity) {
                if (!is_array($entity) || !isset($entity['versions']) || !is_array($entity['versions'])) {
                    continue;
                }

                $count += count($entity['versions']);
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

    private function resolveEntityVersionKey(array $brain, string $project, string $entitySlug, array $entity, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return $entity['active_version'] ?? null;
        }

        if (\str_starts_with($reference, '@')) {
            $version = \ltrim(\substr($reference, 1));
            if ($version !== '' && isset($entity['versions'][$version])) {
                return $version;
            }
        } elseif (\str_starts_with($reference, '#')) {
            $hash = \substr($reference, 1);
            return $this->resolveCommitVersion($brain, $project, $entitySlug, $hash);
        } else {
            if (isset($entity['versions'][$reference])) {
                return $reference;
            }

            $numeric = (string) (int) $reference;
            if ($numeric !== '0' && isset($entity['versions'][$numeric])) {
                return $numeric;
            }
        }

        return null;
    }

    private function resolveCommitVersion(array $brain, string $project, string $entity, string $hash): ?string
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        $commits = isset($brain['commits']) && \is_array($brain['commits']) ? $brain['commits'] : [];

        foreach ($commits as $commitHash => $commit) {
            if (!\is_array($commit)) {
                continue;
            }

            if (strtolower($commitHash) !== $hash) {
                continue;
            }

            if (($commit['project'] ?? null) !== $project || ($commit['entity'] ?? null) !== $entity) {
                continue;
            }

            return (string) ($commit['version'] ?? '');
        }

        return null;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
