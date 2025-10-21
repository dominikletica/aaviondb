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
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_reverse;
use function array_values;
use function array_column;
use function array_unique;
use function basename;
use function date;
use function implode;
use function array_shift;
use function array_unshift;
use function fclose;
use function feof;
use function file_get_contents;
use function filemtime;
use function fopen;
use function fread;
use function fwrite;
use function glob;
use function gzclose;
use function gzeof;
use function gzopen;
use function gzread;
use function gzwrite;
use function filesize;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function ksort;
use function krsort;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function strtotime;
use function trim;
use function unlink;
use function usort;

/**
 * Manages lifecycle and persistence of system and user brain files.
 */
final class BrainRepository
{
    private const DEFAULT_HIERARCHY_MAX_DEPTH = 10;

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
     * @param array<int, string>|null $pathSegments Optional hierarchy filter (null = all entities, [] = root level).
     *
     * @return array<string, array<string, mixed>>
     */
    public function listEntities(string $projectSlug, ?array $pathSegments = null): array
    {
        $slug = $this->normalizeKey($projectSlug);
        $this->assertReadAllowed($slug);
        $brain = $this->loadActiveBrain();
        $project = $this->getProject($slug);
        $entities = $project['entities'] ?? [];
        $hierarchy =& $this->ensureProjectHierarchy($brain, $slug);
        $result = [];
        $filterSlugs = null;

        if ($pathSegments !== null) {
            if ($pathSegments === []) {
                $filterSlugs = [];
                foreach ($entities as $entitySlug => $entity) {
                    if (!\is_array($entity)) {
                        continue;
                    }
                    if (($entity['status'] ?? 'active') === 'deleted') {
                        continue;
                    }

                    if ($this->hierarchyParent($hierarchy, $entitySlug) === null) {
                        $filterSlugs[] = $entitySlug;
                    }
                }
            } else {
                $parentSlug = $this->resolveHierarchyPath($brain, $slug, $pathSegments);
                $filterSlugs = $this->hierarchyChildren($hierarchy, $parentSlug);
            }
        }

        foreach ($entities as $entitySlug => $entity) {
            if (!\is_array($entity)) {
                continue;
            }

            if (($entity['status'] ?? 'active') === 'deleted') {
                continue;
            }

            if ($filterSlugs !== null && !in_array($entitySlug, $filterSlugs, true)) {
                continue;
            }

            $summary = $this->summarizeEntity($entitySlug, $entity);
            $summary['parent'] = $this->hierarchyParent($hierarchy, $entitySlug);
            $summary['path'] = $this->buildEntityPath($hierarchy, $entitySlug);
            $summary['path_string'] = $summary['path'] !== [] ? implode('/', $summary['path']) : null;
            if ($pathSegments !== null) {
                $summary['listing_parent_path'] = $pathSegments;
            }

            $result[$entitySlug] = $summary;
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
                'fieldset' => $record['fieldset'] ?? ($entity['fieldset'] ?? null),
                'fieldset_version' => $record['fieldset_version'] ?? null,
            ];
        }

        return $result;
    }

    public function listProjectCommits(string $projectSlug, ?string $entitySlug = null): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertReadAllowed($slugProject);

        $brain = $this->loadActiveBrain();
        $commits = isset($brain['commits']) && \is_array($brain['commits']) ? $brain['commits'] : [];

        $filterEntity = $entitySlug !== null ? $this->normalizeKey($entitySlug) : null;
        $result = [];

        foreach ($commits as $hash => $commit) {
            if (!\is_array($commit)) {
                continue;
            }

            if (($commit['project'] ?? null) !== $slugProject) {
                continue;
            }

            $commitEntity = isset($commit['entity']) ? $this->normalizeKey((string) $commit['entity']) : null;
            if ($filterEntity !== null && $commitEntity !== $filterEntity) {
                continue;
            }

            $result[] = [
                'commit' => $hash,
                'entity' => $commit['entity'] ?? null,
                'version' => $commit['version'] ?? null,
                'hash' => $commit['hash'] ?? null,
                'timestamp' => $commit['timestamp'] ?? null,
                'merge' => $commit['merge'] ?? null,
                'fieldset' => $commit['fieldset'] ?? null,
                'source_reference' => $commit['source_reference'] ?? null,
                'fieldset_reference' => $commit['fieldset_reference'] ?? null,
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $leftTime = $left['timestamp'] ?? '';
            $rightTime = $right['timestamp'] ?? '';

            return \strcmp((string) $rightTime, (string) $leftTime);
        });

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

        if (isset($brain['projects'][$slug]['entities']) && \is_array($brain['projects'][$slug]['entities'])) {
            foreach ($brain['projects'][$slug]['entities'] as $entitySlug => &$entity) {
                if (!\is_array($entity)) {
                    continue;
                }

                $entity['status'] = 'inactive';
                $entity['archived_at'] = $timestamp;
                $entity['updated_at'] = $timestamp;

                if (isset($entity['active_version']) && isset($entity['versions'][$entity['active_version']])) {
                    $entity['versions'][$entity['active_version']]['status'] = 'inactive';
                }

                $entity['active_version'] = null;
            }
            unset($entity);
        }

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.archived', [
            'project' => $slug,
        ]);

        return $this->projectReport($slug, false);
    }

    /**
     * Restores an archived project and optionally reactivates its entities.
     *
     * @param array<string, mixed> $options
     */
    public function restoreProject(string $projectSlug, array $options = []): array
    {
        $slug = $this->normalizeKey($projectSlug);
        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slug])) {
            throw new StorageException(sprintf('Project "%s" does not exist.', $projectSlug));
        }

        $this->assertWriteAllowed($slug);

        $project = &$brain['projects'][$slug];

        if (($project['status'] ?? 'active') === 'active') {
            throw new StorageException(sprintf('Project "%s" is already active.', $projectSlug));
        }

        $timestamp = $this->timestamp();
        $project['status'] = 'active';
        $project['archived_at'] = null;
        $project['updated_at'] = $timestamp;

        $reactivateEntities = ($options['reactivate_entities'] ?? false) === true;
        $reactivated = [];
        $warnings = [];

        if ($reactivateEntities && isset($project['entities']) && \is_array($project['entities'])) {
            foreach ($project['entities'] as $entitySlug => &$entity) {
                if (!\is_array($entity)) {
                    continue;
                }

                $outcome = $this->reactivateEntityRecord($entity, $timestamp);
                if ($outcome['reactivated']) {
                    $reactivated[] = $entitySlug;
                } elseif ($outcome['reason'] === 'no_versions') {
                    $warnings[] = sprintf('Entity "%s" has no versions to reactivate.', $entitySlug);
                }
            }
            unset($entity);
        }

        $brain['projects'][$slug] = $project;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.project.restored', [
            'project' => $slug,
            'reactivate_entities' => $reactivateEntities,
            'reactivated_entities' => $reactivated,
        ]);

        return [
            'project' => $this->projectReport($slug, false),
            'reactivate_entities' => $reactivateEntities,
            'reactivated_entities' => $reactivated,
            'warnings' => $warnings,
        ];
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

        $brain = $this->loadActiveBrain();
        $project = $this->getProject($slugProject);

        if (!isset($project['entities'][$slugEntity]) || !\is_array($project['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = $project['entities'][$slugEntity];
        $summary = $this->summarizeEntity($slugEntity, $entity);

        if ($includeVersions) {
            $summary['versions'] = $this->listEntityVersions($slugProject, $slugEntity);
        }

        $hierarchy =& $this->ensureProjectHierarchy($brain, $slugProject);
        $summary['parent'] = $this->hierarchyParent($hierarchy, $slugEntity);
        $summary['path'] = $this->buildEntityPath($hierarchy, $slugEntity);
        $summary['path_string'] = $summary['path'] !== [] ? implode('/', $summary['path']) : null;

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

    public function deleteEntity(string $projectSlug, string $entitySlug, bool $purgeCommits = true, array $options = []): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);
        $brain = $this->loadActiveBrain();

        AavionDB::debugLog('Deleting entity.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'purge_commits' => $purgeCommits,
            'source' => 'storage:brain',
        ]);

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $recursive = ($options['recursive'] ?? false) === true;

        $hierarchy =& $this->ensureProjectHierarchy($brain, $slugProject);
        $descendants = $this->collectDescendants($hierarchy, $slugEntity);
        $directChildren = $this->hierarchyChildren($hierarchy, $slugEntity);
        $warnings = [];
        $promotedChildren = [];

        if ($recursive) {
            $deletedDescendants = array_reverse($descendants);
            foreach ($deletedDescendants as $childSlug) {
                $this->deleteSingleEntity($brain, $slugProject, $childSlug, $purgeCommits);
                $this->clearHierarchyEntry($brain, $slugProject, $childSlug);
            }
            if ($deletedDescendants !== []) {
                $warnings[] = sprintf(
                    'Entity "%s" recursively deleted %d descendant%s.',
                    $slugEntity,
                    \count($deletedDescendants),
                    \count($deletedDescendants) === 1 ? '' : 's'
                );
            }
        } else {
            $promotedChildren = $directChildren;
            if ($promotedChildren !== []) {
                $warnings[] = sprintf(
                    'Entity "%s" had %d child%s promoted to the root level before deletion.',
                    $slugEntity,
                    \count($promotedChildren),
                    \count($promotedChildren) === 1 ? '' : 'ren'
                );
            }

            $this->promoteChildren($brain, $slugProject, $slugEntity, null);
        }

        $this->deleteSingleEntity($brain, $slugProject, $slugEntity, $purgeCommits);
        $this->clearHierarchyEntry($brain, $slugProject, $slugEntity);

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.deleted', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'purged_commits' => $purgeCommits,
            'recursive' => $recursive,
            'descendants' => $recursive ? $descendants : [],
            'promoted_children' => $recursive ? [] : $promotedChildren,
        ]);

        AavionDB::debugLog('Entity deleted.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'remaining_entities' => \count($brain['projects'][$slugProject]['entities'] ?? []),
            'source' => 'storage:brain',
        ]);

        return [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'deleted' => true,
            'warnings' => $warnings,
            'cascade' => [
                'recursive' => $recursive,
                'promoted_children' => $promotedChildren,
                'deleted_descendants' => $recursive ? $descendants : [],
            ],
        ];
    }

    public function deleteBrain(string $slug): array
    {
        $normalized = $this->normalizeKey($slug);

        if ($normalized === 'system') {
            throw new StorageException('System brain cannot be deleted.');
        }

        $active = $this->activeBrain();
        if ($active === $normalized) {
            throw new StorageException('Cannot delete the active brain. Switch to a different brain first.');
        }

        $path = $this->paths->userBrain($normalized);
        if (!\is_file($path)) {
            throw new StorageException(sprintf('Brain "%s" does not exist.', $slug));
        }

        if (!@\unlink($path)) {
            throw new StorageException(sprintf('Unable to delete brain "%s".', $slug));
        }

        $this->events->emit('brain.deleted', [
            'slug' => $normalized,
            'path' => $path,
        ]);

        return [
            'slug' => $normalized,
            'path' => $path,
            'deleted' => true,
        ];
    }

    public function deactivateEntity(string $projectSlug, string $entitySlug, array $options = []): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);

        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $recursive = ($options['recursive'] ?? false) === true;

        $timestamp = $this->timestamp();
        $hierarchy =& $this->ensureProjectHierarchy($brain, $slugProject);

        $descendants = $this->collectDescendants($hierarchy, $slugEntity);
        $directChildren = $this->hierarchyChildren($hierarchy, $slugEntity);
        $targets = $recursive ? array_merge($descendants, [$slugEntity]) : [$slugEntity];
        $warnings = [];

        foreach ($targets as $targetSlug) {
            if (!isset($brain['projects'][$slugProject]['entities'][$targetSlug])) {
                continue;
            }

            $entityRef = &$brain['projects'][$slugProject]['entities'][$targetSlug];
            $activeVersion = $entityRef['active_version'] ?? null;

            if ($activeVersion !== null && isset($entityRef['versions'][$activeVersion])) {
                $entityRef['versions'][$activeVersion]['status'] = 'inactive';
            }

            foreach ($entityRef['versions'] ?? [] as &$versionRecord) {
                if (!\is_array($versionRecord)) {
                    continue;
                }
                if (($versionRecord['status'] ?? 'inactive') === 'active') {
                    $versionRecord['status'] = 'inactive';
                }
            }
            unset($versionRecord);

            $entityRef['active_version'] = null;
            $entityRef['status'] = 'inactive';
            $entityRef['archived_at'] = $timestamp;
            $entityRef['updated_at'] = $timestamp;
        }

        $promotedChildren = [];
        if (!$recursive) {
            $promotedChildren = $directChildren;
            if ($promotedChildren !== []) {
                $warnings[] = sprintf(
                    'Entity "%s" had %d child%s promoted to the root level.',
                    $slugEntity,
                    \count($promotedChildren),
                    \count($promotedChildren) === 1 ? '' : 'ren'
                );
            }

            $this->promoteChildren($brain, $slugProject, $slugEntity, null);
        } elseif ($descendants !== []) {
            $warnings[] = sprintf(
                'Entity "%s" recursively affected %d descendant%s.',
                $slugEntity,
                \count($descendants),
                \count($descendants) === 1 ? '' : 's'
            );
        }

        $brain['projects'][$slugProject]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.deactivated', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'recursive' => $recursive,
            'descendants' => $recursive ? $descendants : [],
            'promoted_children' => $recursive ? [] : $promotedChildren,
        ]);

        $report = $this->entityReport($slugProject, $slugEntity, true);
        $report['warnings'] = $warnings;
        $report['cascade'] = [
            'recursive' => $recursive,
            'promoted_children' => $promotedChildren,
            'affected_descendants' => $recursive ? $descendants : [],
        ];

        return $report;
    }

    public function deleteEntityVersion(string $projectSlug, string $entitySlug, string $reference): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);
        $slugEntity = $this->normalizeKey($entitySlug);

        $brain = $this->loadActiveBrain();

        AavionDB::debugLog('Deleting entity version.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'reference' => $reference,
            'source' => 'storage:brain',
        ]);

        if (!isset($brain['projects'][$slugProject]['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $entity = &$brain['projects'][$slugProject]['entities'][$slugEntity];
        $versionKey = $this->resolveEntityVersionKey($brain, $slugProject, $slugEntity, $entity, $reference);

        if ($versionKey === null) {
            throw new StorageException(sprintf('Unknown entity reference "%s".', $reference));
        }

        $record = $entity['versions'][$versionKey] ?? null;
        unset($entity['versions'][$versionKey]);

        if (is_array($record) && isset($record['commit']) && is_string($record['commit'])) {
            unset($brain['commits'][$record['commit']]);
        } else {
            foreach ($brain['commits'] ?? [] as $hash => $commit) {
                if (!is_array($commit)) {
                    continue;
                }

                if (($commit['project'] ?? null) === $slugProject
                    && ($commit['entity'] ?? null) === $slugEntity
                    && ($commit['version'] ?? null) === (string) $versionKey) {
                    unset($brain['commits'][$hash]);
                }
            }
        }

        $activeChanged = ($entity['active_version'] ?? null) === $versionKey;

        if ($activeChanged) {
            $entity['active_version'] = null;

            if ($entity['versions'] !== []) {
                $keys = array_keys($entity['versions']);
                \rsort($keys, SORT_NUMERIC);

                foreach ($keys as $key) {
                    if (!isset($entity['versions'][$key])) {
                        continue;
                    }

                    $entity['active_version'] = (string) $key;
                    $entity['versions'][$key]['status'] = 'active';
                    break;
                }

                foreach ($entity['versions'] as $key => &$candidate) {
                    if ((string) $key !== ($entity['active_version'] ?? '')) {
                        $candidate['status'] = 'inactive';
                    }
                }
                unset($candidate);
            } else {
                $entity['status'] = 'inactive';
            }
        }

        $timestamp = $this->timestamp();
        $entity['updated_at'] = $timestamp;
        $brain['projects'][$slugProject]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.version.deleted', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => $versionKey,
            'reference' => $reference,
        ]);

        AavionDB::debugLog('Entity version deleted.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'removed_version' => $versionKey,
            'remaining_versions' => \count($entity['versions'] ?? []),
            'source' => 'storage:brain',
        ]);

        return [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $versionKey,
            'reference' => $reference,
            'active_version' => $entity['active_version'] ?? null,
            'version_count' => \count($entity['versions'] ?? []),
        ];
    }

    public function purgeInactiveEntityVersions(string $projectSlug, ?string $entitySlug = null, int $keep = 0, bool $dryRun = false): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $this->assertWriteAllowed($slugProject);

        $brain = $this->loadActiveBrain();

        if (!isset($brain['projects'][$slugProject]) || !\is_array($brain['projects'][$slugProject])) {
            throw new StorageException(\sprintf('Project "%s" not found in active brain.', $projectSlug));
        }

        $normalizedEntity = $entitySlug !== null ? $this->normalizeKey($entitySlug) : null;

        AavionDB::debugLog('Purging inactive versions.', [
            'project' => $slugProject,
            'entity' => $normalizedEntity,
            'keep' => $keep,
            'dry_run' => $dryRun,
            'source' => 'storage:brain',
        ]);

        $entities = $brain['projects'][$slugProject]['entities'] ?? [];
        if (!\is_array($entities)) {
            $entities = [];
        }

        $targets = [];

        if ($normalizedEntity !== null) {
            if (!isset($entities[$normalizedEntity]) || !\is_array($entities[$normalizedEntity])) {
                throw new StorageException(\sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
            }

            $targets[$normalizedEntity] = $entities[$normalizedEntity];
        } else {
            foreach ($entities as $slug => $entity) {
                if (!\is_array($entity)) {
                    continue;
                }
                $targets[$slug] = $entity;
            }
        }

        $keep = \max(0, $keep);
        $timestamp = $this->timestamp();

        $plan = [];
        $removedTotal = 0;
        $removedCommits = 0;

        foreach ($targets as $slug => $entity) {
            if (!isset($entity['versions']) || !\is_array($entity['versions'])) {
                continue;
            }

            $versions = $entity['versions'];
            $initial = \count($versions);

            if ($initial === 0) {
                $plan[] = [
                    'entity' => $slug,
                    'initial_versions' => 0,
                    'removed_versions' => [],
                    'kept_versions' => [],
                    'active_version' => $entity['active_version'] ?? null,
                    'commits_removed' => 0,
                ];
                continue;
            }

            $activeVersion = isset($entity['active_version']) ? (string) $entity['active_version'] : null;

            $keepCandidates = [];
            if ($keep > 0) {
                $versionKeys = \array_keys($versions);
                \rsort($versionKeys, SORT_NUMERIC);
                $keepCandidates = \array_map('strval', \array_slice($versionKeys, 0, $keep));
            }

            if ($activeVersion !== null) {
                $keepCandidates[] = $activeVersion;
            }

            $keepCandidates = \array_unique($keepCandidates);

            $versionsToRemove = [];
            $commitsToRemove = [];

            foreach ($versions as $key => $record) {
                $keyStr = (string) $key;
                $status = $record['status'] ?? 'inactive';

                if (\in_array($keyStr, $keepCandidates, true)) {
                    continue;
                }

                if ($status === 'active') {
                    $keepCandidates[] = $keyStr;
                    continue;
                }

                $versionsToRemove[] = $keyStr;

                $commitHash = null;
                if (isset($record['commit']) && \is_string($record['commit']) && $record['commit'] !== '') {
                    $commitHash = $record['commit'];
                } else {
                    $commitHash = $this->findCommitHashForVersion($brain, $slugProject, $slug, $keyStr);
                }

                if ($commitHash !== null) {
                    $commitsToRemove[] = $commitHash;
                }
            }

            $versionsToRemove = \array_values($versionsToRemove);
            $commitsToRemove = \array_values(\array_unique(\array_filter($commitsToRemove, static fn ($value) => \is_string($value) && $value !== '')));

            $plan[] = [
                'entity' => $slug,
                'initial_versions' => $initial,
                'removed_versions' => $versionsToRemove,
                'kept_versions' => $keepCandidates,
                'active_version' => $activeVersion,
                'commits_removed' => \count($commitsToRemove),
            ];

            $removedTotal += \count($versionsToRemove);
            $removedCommits += \count($commitsToRemove);
        }

        $result = [
            'project' => $slugProject,
            'entity' => $normalizedEntity,
            'removed_versions' => $removedTotal,
            'removed_commits' => $removedCommits,
            'keep' => $keep,
            'dry_run' => $dryRun,
            'details' => $plan,
        ];

        if ($dryRun || $removedTotal === 0) {
            return $result;
        }

        $workingBrain = $brain;

        foreach ($plan as $entry) {
            $entitySlug = $entry['entity'];
            if (!isset($workingBrain['projects'][$slugProject]['entities'][$entitySlug]['versions'])) {
                continue;
            }

            foreach ($entry['removed_versions'] as $versionKey) {
                if (!isset($workingBrain['projects'][$slugProject]['entities'][$entitySlug]['versions'][$versionKey])) {
                    continue;
                }

                $record = $workingBrain['projects'][$slugProject]['entities'][$entitySlug]['versions'][$versionKey];
                $this->removeCommitReference($workingBrain, $slugProject, $entitySlug, $versionKey, \is_array($record) ? $record : null);
                unset($workingBrain['projects'][$slugProject]['entities'][$entitySlug]['versions'][$versionKey]);
            }

            if (\count($entry['removed_versions']) > 0) {
                $workingBrain['projects'][$slugProject]['entities'][$entitySlug]['updated_at'] = $timestamp;
            }
        }

        $workingBrain['projects'][$slugProject]['updated_at'] = $timestamp;
        $workingBrain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $workingBrain;
        $this->persistActiveBrain();

        $this->events->emit('brain.entity.cleanup', [
            'project' => $slugProject,
            'entity' => $normalizedEntity,
            'removed_versions' => $removedTotal,
            'removed_commits' => $removedCommits,
            'keep' => $keep,
        ]);

        AavionDB::debugLog('Inactive versions purged.', [
            'project' => $slugProject,
            'entity' => $normalizedEntity,
            'removed_versions' => $removedTotal,
            'removed_commits' => $removedCommits,
            'keep' => $keep,
            'source' => 'storage:brain',
        ]);

        return $result;
    }

    public function compactBrain(?string $projectSlug = null, bool $dryRun = false): array
    {
        $brain = $this->loadActiveBrain();
        $targets = $this->resolveProjectTargets($brain, $projectSlug, true);

        $timestamp = $this->timestamp();
        $existingCommits = isset($brain['commits']) && \is_array($brain['commits']) ? $brain['commits'] : [];
        $newCommits = $existingCommits;

        $summary = [];
        $totalRemoved = 0;
        $totalAdded = 0;
        $entitiesReordered = 0;
        $modified = false;

        foreach ($targets as $slug => $project) {
            $projectRemoved = 0;
            $projectAdded = 0;
            $projectEntities = [];
            $projectModified = false;

            if (!isset($project['entities']) || !\is_array($project['entities'])) {
                $summary[] = [
                    'slug' => $slug,
                    'entities' => [],
                    'commits_removed' => 0,
                    'commits_added' => 0,
                ];
                continue;
            }

            foreach ($existingCommits as $hash => $commit) {
                if (!\is_array($commit)) {
                    continue;
                }

                if (($commit['project'] ?? null) !== $slug) {
                    continue;
                }

                if (isset($newCommits[$hash])) {
                    unset($newCommits[$hash]);
                    ++$projectRemoved;
                }
            }

            foreach ($project['entities'] as $entitySlug => $entity) {
                if (!\is_array($entity) || !isset($entity['versions']) || !\is_array($entity['versions'])) {
                    $projectEntities[] = [
                        'slug' => $entitySlug,
                        'versions_reordered' => false,
                        'commits_linked' => 0,
                    ];
                    continue;
                }

                $versions = $entity['versions'];
                $orderedKeys = \array_keys($versions);
                $sortedKeys = $orderedKeys;
                \usort($sortedKeys, static fn ($a, $b) => (int) $a <=> (int) $b);

                $reordered = $orderedKeys !== $sortedKeys;

                if ($reordered) {
                    $sortedVersions = [];
                    foreach ($sortedKeys as $versionKey) {
                        $sortedVersions[(string) $versionKey] = $versions[$versionKey];
                    }
                    $versions = $sortedVersions;
                    $brain['projects'][$slug]['entities'][$entitySlug]['versions'] = $versions;
                    $projectModified = true;
                    ++$entitiesReordered;
                }

                $linkedCommits = 0;

                foreach ($versions as $versionKey => $record) {
                    if (!\is_array($record)) {
                        continue;
                    }

                    if (!isset($record['commit']) || !\is_string($record['commit']) || $record['commit'] === '') {
                        continue;
                    }

                    $commitHash = $record['commit'];
                    $existing = $existingCommits[$commitHash] ?? null;

                    $entry = \is_array($existing) ? $existing : [];
                    $entry['project'] = $slug;
                    $entry['entity'] = $entitySlug;
                    $entry['version'] = (string) $versionKey;

                    if (isset($record['hash'])) {
                        $entry['hash'] = $record['hash'];
                    }

                    if (isset($record['committed_at'])) {
                        $entry['timestamp'] = $record['committed_at'];
                    } elseif (!isset($entry['timestamp'])) {
                        $entry['timestamp'] = $timestamp;
                    }

                    if (isset($record['merge'])) {
                        $entry['merge'] = $record['merge'];
                    } elseif (isset($existing['merge'])) {
                        $entry['merge'] = $existing['merge'];
                    }

                    if (isset($record['fieldset'])) {
                        $entry['fieldset'] = $record['fieldset'];
                    } elseif (isset($entity['fieldset'])) {
                        $entry['fieldset'] = $entity['fieldset'];
                    } elseif (isset($existing['fieldset'])) {
                        $entry['fieldset'] = $existing['fieldset'];
                    }

                    if (isset($record['source_reference'])) {
                        $entry['source_reference'] = $record['source_reference'];
                    } elseif (isset($existing['source_reference'])) {
                        $entry['source_reference'] = $existing['source_reference'];
                    }

                    if (isset($record['fieldset_reference'])) {
                        $entry['fieldset_reference'] = $record['fieldset_reference'];
                    } elseif (isset($existing['fieldset_reference'])) {
                        $entry['fieldset_reference'] = $existing['fieldset_reference'];
                    }

                    $newCommits[$commitHash] = $entry;

                    if ($existing === null) {
                        ++$projectAdded;
                    }

                    ++$linkedCommits;
                }

                $projectEntities[] = [
                    'slug' => $entitySlug,
                    'versions_reordered' => $reordered,
                    'commits_linked' => $linkedCommits,
                ];
            }

            if ($projectRemoved > 0 || $projectAdded > 0 || $projectModified) {
                $modified = true;
                $brain['projects'][$slug]['updated_at'] = $timestamp;
            }

            $summary[] = [
                'slug' => $slug,
                'entities' => $projectEntities,
                'commits_removed' => $projectRemoved,
                'commits_added' => $projectAdded,
            ];

            $totalRemoved += $projectRemoved;
            $totalAdded += $projectAdded;
        }

        if (!$modified || $dryRun) {
            return [
                'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
                'dry_run' => $dryRun,
                'projects' => $summary,
                'commits_removed' => $totalRemoved,
                'commits_added' => $totalAdded,
                'entities_reordered' => $entitiesReordered,
            ];
        }

        \ksort($newCommits);
        $brain['commits'] = $newCommits;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.compacted', [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'commits_removed' => $totalRemoved,
            'commits_added' => $totalAdded,
            'entities_reordered' => $entitiesReordered,
        ]);

        AavionDB::debugLog('Brain compacted.', [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'commits_removed' => $totalRemoved,
            'commits_added' => $totalAdded,
            'entities_reordered' => $entitiesReordered,
            'source' => 'storage:brain',
        ]);

        return [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'dry_run' => false,
            'projects' => $summary,
            'commits_removed' => $totalRemoved,
            'commits_added' => $totalAdded,
            'entities_reordered' => $entitiesReordered,
        ];
    }

    public function repairBrain(?string $projectSlug = null, bool $dryRun = false): array
    {
        $brain = $this->loadActiveBrain();
        $targets = $this->resolveProjectTargets($brain, $projectSlug, true);

        $timestamp = $this->timestamp();
        $summary = [];
        $entitiesRepaired = 0;
        $projectUpdates = 0;
        $modified = false;

        foreach ($targets as $slug => $project) {
            $entities = $project['entities'] ?? [];
            if (!\is_array($entities)) {
                $entities = [];
            }

            $entitySummaries = [];
            $projectModified = false;

            foreach ($entities as $entitySlug => $entity) {
                if (!\is_array($entity)) {
                    continue;
                }

                $versions = $entity['versions'] ?? [];
                if (!\is_array($versions)) {
                    $versions = [];
                }

                $actions = [];

                if ($versions === []) {
                    if (($entity['active_version'] ?? null) !== null) {
                        $entity['active_version'] = null;
                        $actions[] = 'cleared_active_version';
                    }
                    if (($entity['status'] ?? 'inactive') !== 'inactive') {
                        $entity['status'] = 'inactive';
                        $actions[] = 'status_inactive';
                    }
                } else {
                    $activeVersion = $entity['active_version'] ?? null;

                    if ($activeVersion === null || !isset($versions[$activeVersion])) {
                        $candidate = null;
                        foreach ($versions as $key => $record) {
                            if (($record['status'] ?? 'inactive') === 'active') {
                                $candidate = (string) $key;
                                break;
                            }
                        }

                        if ($candidate === null) {
                            $keys = \array_keys($versions);
                            \usort($keys, static fn ($a, $b) => (int) $b <=> (int) $a);
                            $candidate = (string) ($keys[0] ?? '');
                        }

                        if ($candidate !== null && $candidate !== '') {
                            $entity['active_version'] = $candidate;
                            $activeVersion = $candidate;
                            $actions[] = 'active_version_reset';
                        }
                    }

                    foreach ($versions as $key => &$record) {
                        if (!\is_array($record)) {
                            continue;
                        }

                        $shouldBe = ((string) $key === (string) ($entity['active_version'] ?? null)) ? 'active' : 'inactive';

                        if (($record['status'] ?? $shouldBe) !== $shouldBe) {
                            $record['status'] = $shouldBe;
                            $actions[] = 'version_status_adjusted';
                        }

                        if (!isset($record['committed_at'])) {
                            $record['committed_at'] = $timestamp;
                            $actions[] = 'committed_at_filled';
                        }
                    }
                    unset($record);

                    $entity['versions'] = $versions;

                    $hasActive = false;
                    foreach ($versions as $record) {
                        if (($record['status'] ?? 'inactive') === 'active') {
                            $hasActive = true;
                            break;
                        }
                    }

                    $desiredStatus = $hasActive ? 'active' : 'inactive';
                    if (($entity['status'] ?? $desiredStatus) !== $desiredStatus) {
                        $entity['status'] = $desiredStatus;
                        $actions[] = 'entity_status_align';
                    }
                }

                if (!isset($entity['created_at']) && $versions !== []) {
                    $times = \array_column($versions, 'committed_at');
                    $times = \array_filter($times, static fn ($value) => \is_string($value) && $value !== '');
                    if ($times !== []) {
                        \sort($times);
                        $entity['created_at'] = $times[0];
                        $actions[] = 'created_at_filled';
                    } else {
                        $entity['created_at'] = $timestamp;
                        $actions[] = 'created_at_default';
                    }
                }

                if (!isset($entity['updated_at']) && $versions !== []) {
                    $times = \array_column($versions, 'committed_at');
                    $times = \array_filter($times, static fn ($value) => \is_string($value) && $value !== '');
                    if ($times !== []) {
                        \sort($times);
                        $entity['updated_at'] = $times[\count($times) - 1];
                        $actions[] = 'updated_at_filled';
                    }
                }

                if ($actions !== []) {
                    $brain['projects'][$slug]['entities'][$entitySlug] = $entity;
                    $entitySummaries[] = [
                        'slug' => $entitySlug,
                        'actions' => \array_values(\array_unique($actions)),
                    ];
                    ++$entitiesRepaired;
                    $projectModified = true;
                }
            }

            if ($projectModified) {
                $brain['projects'][$slug]['updated_at'] = $timestamp;
                $projectUpdates++;
                $modified = true;
            }

            $summary[] = [
                'slug' => $slug,
                'entities' => $entitySummaries,
            ];
        }

        if (!$modified || $dryRun) {
            return [
                'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
                'dry_run' => $dryRun,
                'projects' => $summary,
                'entities_repaired' => $entitiesRepaired,
                'projects_updated' => $projectUpdates,
            ];
        }

        $brain['meta']['updated_at'] = $timestamp;
        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $this->events->emit('brain.repaired', [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'entities_repaired' => $entitiesRepaired,
            'projects_updated' => $projectUpdates,
        ]);

        AavionDB::debugLog('Brain repaired.', [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'entities_repaired' => $entitiesRepaired,
            'projects_updated' => $projectUpdates,
            'source' => 'storage:brain',
        ]);

        return [
            'project' => $projectSlug !== null ? $this->normalizeKey($projectSlug) : null,
            'dry_run' => false,
            'projects' => $summary,
            'entities_repaired' => $entitiesRepaired,
            'projects_updated' => $projectUpdates,
        ];
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

        AavionDB::debugLog('Saving entity to brain.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'options' => $options,
            'source' => 'storage:brain',
        ]);

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
                'fieldset_version' => null,
                'versions' => [],
            ];
        }

        $entity = &$project['entities'][$slugEntity];
        $entity['status'] = 'active';
        $entity['archived_at'] = null;

        $this->ensureProjectHierarchy($brain, $slugProject);

        $hierarchyInfo = null;
        if (isset($options['parent_path']) && \is_array($options['parent_path'])) {
            $desiredPath = array_values(array_filter(array_map('strval', $options['parent_path']), static fn ($part) => $part !== ''));
            $hierarchyInfo = $this->resolveParentPath($brain, $slugProject, $slugEntity, $desiredPath);
            $this->assignEntityParent($brain, $slugProject, $slugEntity, $hierarchyInfo['parent']);
        }

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
                $schemaReference = $fieldsetReference;
                if ($schemaReference === null && isset($entity['fieldset_version']) && $entity['fieldset_version'] !== null) {
                    $schemaReference = '@' . $entity['fieldset_version'];
                }

                $schemaDefinition = $this->resolveSchemaDefinition($desiredFieldset, $schemaReference);
                $schemaPayload = $schemaDefinition['payload'];
                try {
                    $mergedPayload = $this->schemaValidator()->applySchema($mergedPayload, $schemaPayload);
                } catch (SchemaException $exception) {
                    throw new StorageException(sprintf('Payload for entity "%s" violates schema "%s": %s', $slugEntity, $desiredFieldset, $exception->getMessage()), 0, $exception);
                }

                $entity['fieldset'] = $desiredFieldset;
                $entity['fieldset_version'] = $schemaDefinition['version'];
                $schemaVersion = $schemaDefinition['version'];
            } else {
                $entity['fieldset'] = null;
                $fieldsetReference = null;
                $entity['fieldset_version'] = null;
                $schemaVersion = null;
            }
        }
        if (!isset($schemaVersion)) {
            $schemaVersion = $entity['fieldset_version'] ?? null;
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
            'fieldset_version' => $schemaVersion,
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
            'fieldset_version' => $schemaVersion,
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
            'fieldset_version' => $schemaVersion,
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

        $hierarchyState = [
            'parent' => $this->hierarchyParent($brain['projects'][$slugProject]['hierarchy'], $slugEntity),
            'path' => $this->buildEntityPath($brain['projects'][$slugProject]['hierarchy'], $slugEntity),
            'warnings' => $hierarchyInfo['warnings'] ?? [],
        ];

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

        AavionDB::debugLog('Entity saved.', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'version' => (string) $currentVersion,
            'commit' => $commitHash,
            'source' => 'storage:brain',
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
            'fieldset_version' => $schemaVersion,
            'source_reference' => $sourceReference,
            'fieldset_reference' => $fieldsetReference,
            'hierarchy' => $hierarchyState,
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
    public function listPresets(): array
    {
        $brain = $this->loadSystemBrain();
        $presets = $brain['export']['presets'] ?? [];

        if (!\is_array($presets)) {
            return [];
        }

        ksort($presets);

        $result = [];
        foreach ($presets as $slug => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $meta = isset($definition['meta']) && \is_array($definition['meta'])
                ? $definition['meta']
                : [];

            $result[] = [
                'slug' => $slug,
                'description' => $meta['description'] ?? null,
                'usage' => $meta['usage'] ?? null,
                'layout' => $meta['layout'] ?? null,
                'created_at' => $meta['created_at'] ?? null,
                'updated_at' => $meta['updated_at'] ?? null,
                'tags' => $meta['tags'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPreset(string $slug): ?array
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            return null;
        }

        $brain = $this->loadSystemBrain();
        $definition = $brain['export']['presets'][$normalized] ?? null;

        if (!\is_array($definition)) {
            return null;
        }

        $meta = isset($definition['meta']) && \is_array($definition['meta']) ? $definition['meta'] : [];
        $meta['slug'] = $normalized;
        $definition['meta'] = $meta;

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function savePreset(string $slug, array $definition, bool $allowUpdate = true): array
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            throw new StorageException('Preset slug must not be empty.');
        }

        $timestamp = $this->timestamp();
        $saved = null;

        $this->updateSystemBrain(function (array &$brain) use ($normalized, $definition, $allowUpdate, $timestamp, &$saved): void {
            $export =& $this->ensureExportSection($brain);
            $presets =& $export['presets'];

            $existing = isset($presets[$normalized]) && \is_array($presets[$normalized])
                ? $presets[$normalized]
                : null;

            if ($existing !== null && !$allowUpdate) {
                throw new StorageException(sprintf('Preset "%s" already exists.', $normalized));
            }

            $record = $definition;
            $meta = isset($record['meta']) && \is_array($record['meta']) ? $record['meta'] : [];

            if ($existing !== null) {
                $previousMeta = isset($existing['meta']) && \is_array($existing['meta']) ? $existing['meta'] : [];
                $meta['created_at'] = $previousMeta['created_at'] ?? ($meta['created_at'] ?? $timestamp);
            } else {
                $meta['created_at'] = $meta['created_at'] ?? $timestamp;
            }

            $meta['updated_at'] = $timestamp;
            $meta['slug'] = $normalized;

            $record['meta'] = $meta;
            $presets[$normalized] = $record;
            $saved = $record;
        });

        return $saved ?? [];
    }

    public function deletePreset(string $slug): void
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            throw new StorageException('Preset slug must not be empty.');
        }

        $this->updateSystemBrain(function (array &$brain) use ($normalized): void {
            if (!isset($brain['export']['presets'][$normalized])) {
                throw new StorageException(sprintf('Preset "%s" does not exist.', $normalized));
            }

            unset($brain['export']['presets'][$normalized]);
        });
    }

    public function presetExists(string $slug): bool
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            return false;
        }

        $brain = $this->loadSystemBrain();

        return isset($brain['export']['presets'][$normalized]) && \is_array($brain['export']['presets'][$normalized]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listExportLayouts(): array
    {
        $brain = $this->loadSystemBrain();
        $layouts = $brain['export']['layouts'] ?? [];

        if (!\is_array($layouts)) {
            return [];
        }

        ksort($layouts);
        $result = [];

        foreach ($layouts as $id => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $meta = isset($definition['meta']) && \is_array($definition['meta']) ? $definition['meta'] : [];
            $result[] = [
                'id' => $id,
                'description' => $meta['description'] ?? null,
                'format' => $definition['format'] ?? 'json',
                'updated_at' => $meta['updated_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExportLayout(string $id): ?array
    {
        $normalized = $this->normalizeKey($id);
        if ($normalized === '') {
            return null;
        }

        $brain = $this->loadSystemBrain();
        $layout = $brain['export']['layouts'][$normalized] ?? null;

        if (!\is_array($layout)) {
            return null;
        }

        $meta = isset($layout['meta']) && \is_array($layout['meta']) ? $layout['meta'] : [];
        $meta['id'] = $normalized;
        $layout['meta'] = $meta;

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<string, mixed>
     */
    public function saveExportLayout(string $id, array $layout, bool $allowUpdate = true): array
    {
        $normalized = $this->normalizeKey($id);
        if ($normalized === '') {
            throw new StorageException('Layout identifier must not be empty.');
        }

        $timestamp = $this->timestamp();
        $saved = null;

        $this->updateSystemBrain(function (array &$brain) use ($normalized, $layout, $allowUpdate, $timestamp, &$saved): void {
            $export =& $this->ensureExportSection($brain);
            $layouts =& $export['layouts'];

            $existing = isset($layouts[$normalized]) && \is_array($layouts[$normalized])
                ? $layouts[$normalized]
                : null;

            if ($existing !== null && !$allowUpdate) {
                throw new StorageException(sprintf('Layout "%s" already exists.', $normalized));
            }

            $record = $layout;
            $meta = isset($record['meta']) && \is_array($record['meta']) ? $record['meta'] : [];

            if ($existing !== null) {
                $previousMeta = isset($existing['meta']) && \is_array($existing['meta']) ? $existing['meta'] : [];
                $meta['created_at'] = $previousMeta['created_at'] ?? ($meta['created_at'] ?? $timestamp);
            } else {
                $meta['created_at'] = $meta['created_at'] ?? $timestamp;
            }

            $meta['updated_at'] = $timestamp;
            $meta['id'] = $normalized;

            $record['meta'] = $meta;
            $layouts[$normalized] = $record;
            $saved = $record;
        });

        return $saved ?? [];
    }

    public function deleteExportLayout(string $id): void
    {
        $normalized = $this->normalizeKey($id);
        if ($normalized === '') {
            throw new StorageException('Layout identifier must not be empty.');
        }

        $this->updateSystemBrain(function (array &$brain) use ($normalized): void {
            if (!isset($brain['export']['layouts'][$normalized])) {
                throw new StorageException(sprintf('Layout "%s" does not exist.', $normalized));
            }

            unset($brain['export']['layouts'][$normalized]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSchedulerTasks(): array
    {
        $brain = $this->loadSystemBrain();
        $tasks = $brain['scheduler']['tasks'] ?? [];

        if (!\is_array($tasks)) {
            return [];
        }

        ksort($tasks);

        $result = [];
        foreach ($tasks as $slug => $task) {
            if (!\is_array($task)) {
                continue;
            }

            $task['slug'] = $slug;
            $result[] = $task;
        }

        return $result;
    }

    public function getSchedulerTask(string $slug): ?array
    {
        $normalized = $this->normalizeKey($slug);
        $brain = $this->loadSystemBrain();

        $task = $brain['scheduler']['tasks'][$normalized] ?? null;

        if (!\is_array($task)) {
            return null;
        }

        $task['slug'] = $normalized;

        return $task;
    }

    public function createSchedulerTask(string $slug, string $command): array
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            throw new StorageException('Scheduler task slug must not be empty.');
        }

        $command = trim($command);
        if ($command === '') {
            throw new StorageException('Scheduler command must not be empty.');
        }

        $timestamp = $this->timestamp();
        $record = null;

        $this->updateSystemBrain(function (array &$brain) use ($normalized, $command, $timestamp, &$record): void {
            if (!isset($brain['scheduler']) || !\is_array($brain['scheduler'])) {
                $brain['scheduler'] = [];
            }

            if (!isset($brain['scheduler']['tasks']) || !\is_array($brain['scheduler']['tasks'])) {
                $brain['scheduler']['tasks'] = [];
            }

            if (isset($brain['scheduler']['tasks'][$normalized])) {
                throw new StorageException(sprintf('Scheduler task "%s" already exists.', $normalized));
            }

            $record = [
                'slug' => $normalized,
                'command' => $command,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'last_run_at' => null,
                'last_status' => null,
                'last_message' => null,
            ];

            $brain['scheduler']['tasks'][$normalized] = $record;
        });

        return $record ?? [];
    }

    public function updateSchedulerTask(string $slug, string $command): array
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            throw new StorageException('Scheduler task slug must not be empty.');
        }

        $command = trim($command);
        if ($command === '') {
            throw new StorageException('Scheduler command must not be empty.');
        }

        $timestamp = $this->timestamp();
        $record = null;

        $this->updateSystemBrain(function (array &$brain) use ($normalized, $command, $timestamp, &$record): void {
            if (!isset($brain['scheduler']['tasks'][$normalized]) || !\is_array($brain['scheduler']['tasks'][$normalized])) {
                throw new StorageException(sprintf('Scheduler task "%s" does not exist.', $normalized));
            }

            $task = $brain['scheduler']['tasks'][$normalized];
            $task['command'] = $command;
            $task['updated_at'] = $timestamp;
            $brain['scheduler']['tasks'][$normalized] = $task;
            $record = $task;
        });

        $record['slug'] = $normalized;

        return $record;
    }

    public function deleteSchedulerTask(string $slug): void
    {
        $normalized = $this->normalizeKey($slug);
        if ($normalized === '') {
            throw new StorageException('Scheduler task slug must not be empty.');
        }

        $this->updateSystemBrain(function (array &$brain) use ($normalized): void {
            if (!isset($brain['scheduler']['tasks'][$normalized])) {
                throw new StorageException(sprintf('Scheduler task "%s" does not exist.', $normalized));
            }

            unset($brain['scheduler']['tasks'][$normalized]);
        });
    }

    public function updateSchedulerTaskRun(string $slug, string $status, string $timestamp, ?string $message = null): void
    {
        $normalized = $this->normalizeKey($slug);

        $this->updateSystemBrain(function (array &$brain) use ($normalized, $status, $timestamp, $message): void {
            if (!isset($brain['scheduler']['tasks'][$normalized]) || !\is_array($brain['scheduler']['tasks'][$normalized])) {
                return;
            }

            $task = $brain['scheduler']['tasks'][$normalized];
            $task['last_run_at'] = $timestamp;
            $task['last_status'] = $status;
            $task['last_message'] = $message;
            $task['updated_at'] = $timestamp;
            $brain['scheduler']['tasks'][$normalized] = $task;
        });
    }

    public function recordSchedulerRun(array $results, int $durationMs, int $maxEntries = 100): array
    {
        $timestamp = $this->timestamp();
        $entry = [
            'timestamp' => $timestamp,
            'duration_ms' => $durationMs,
            'results' => $results,
        ];

        $this->updateSystemBrain(function (array &$brain) use (&$entry, $maxEntries): void {
            if (!isset($brain['scheduler']) || !\is_array($brain['scheduler'])) {
                $brain['scheduler'] = [];
            }

            if (!isset($brain['scheduler']['log']) || !\is_array($brain['scheduler']['log'])) {
                $brain['scheduler']['log'] = [];
            }

            $brain['scheduler']['log'][] = $entry;

            if ($maxEntries > 0 && \count($brain['scheduler']['log']) > $maxEntries) {
                $brain['scheduler']['log'] = array_slice($brain['scheduler']['log'], -1 * $maxEntries);
            }
        });

        return $entry;
    }

    public function listSchedulerLog(int $limit = 20): array
    {
        $brain = $this->loadSystemBrain();
        $log = $brain['scheduler']['log'] ?? [];

        if (!\is_array($log) || $log === []) {
            return [];
        }

        $entries = array_slice($log, -1 * max(1, $limit));
        return array_reverse($entries);
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
    public function backupBrain(?string $slug = null, ?string $label = null, bool $compress = false): array
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
        $normalizedLabel = null;
        if ($label !== null && $label !== '') {
            $candidateLabel = $this->sanitizeBackupLabel($label);
            if ($candidateLabel !== '') {
                $normalizedLabel = $candidateLabel;
                $labelPart = '--' . $normalizedLabel;
            }
        }

        $timestamp = (new DateTimeImmutable())->format('Ymd_His');
        $filename = \sprintf('%s%s-%s.brain', $slug, $labelPart, $timestamp);
        $destination = $this->paths->userBackups() . DIRECTORY_SEPARATOR . $filename;

        AavionDB::debugLog('Creating brain backup.', [
            'slug' => $slug,
            'source' => $source,
            'destination' => $destination,
            'label' => $label,
            'source_brain' => $isSystem ? 'system' : 'user',
            'source' => 'storage:brain',
            'compress' => $compress,
        ]);

        if (!@\copy($source, $destination)) {
            throw new StorageException('Unable to create brain backup.');
        }

        $compressed = false;

        if ($compress) {
            $compressedPath = $this->compressBackup($destination);
            $destination = $compressedPath;
            $compressed = true;
            $filename = \basename($destination);
        }

        $bytes = @\filesize($destination) ?: null;

        $this->events->emit('brain.backup.created', [
            'slug' => $slug,
            'path' => $destination,
            'bytes' => $bytes,
            'is_system' => $isSystem,
            'compressed' => $compressed,
        ]);

        AavionDB::debugLog('Brain backup created.', [
            'slug' => $slug,
            'path' => $destination,
            'bytes' => $bytes,
            'source' => 'storage:brain',
            'compressed' => $compressed,
        ]);

        return [
            'slug' => $slug,
            'path' => $destination,
            'filename' => $filename,
            'bytes' => $bytes,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'label' => $normalizedLabel,
            'compressed' => $compressed,
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
     * @return array<string, mixed>
     */
    public function listBackups(?string $slug = null): array
    {
        $directory = $this->paths->userBackups();
        $pattern = $directory . DIRECTORY_SEPARATOR . '*.brain*';
        $files = \glob($pattern) ?: [];

        $normalizedSlug = null;
        if ($slug !== null && $slug !== '' && $slug !== '*') {
            $normalizedSlug = $this->sanitizeBrainSlug($slug);
        }

        $entries = [];

        foreach ($files as $file) {
            if (!\is_file($file)) {
                continue;
            }

            $meta = $this->backupMetadataFromPath($file);
            if ($meta === null) {
                continue;
            }

            if ($normalizedSlug !== null && $meta['slug'] !== $normalizedSlug) {
                continue;
            }

            $entries[] = $meta;
        }

        \usort($entries, static function (array $a, array $b): int {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return [
            'count' => \count($entries),
            'backups' => $entries,
        ];
    }

    public function pruneBackups(?string $slug = null, ?int $keep = null, ?int $olderThanDays = null, bool $dryRun = false): array
    {
        $keep = $keep !== null ? \max(0, $keep) : null;
        $olderThanSeconds = null;
        if ($olderThanDays !== null) {
            $olderThanSeconds = \max(0, $olderThanDays) * 86400;
        }

        if ($keep === null && $olderThanSeconds === null) {
            return [
                'dry_run' => $dryRun,
                'removed' => [],
                'count' => 0,
                'skipped' => true,
                'reason' => 'No retention criteria (keep/older-than) supplied.',
            ];
        }

        $all = $this->listBackups($slug === '*' ? null : $slug);
        $backups = $all['backups'];

        $now = time();
        $grouped = [];

        foreach ($backups as $meta) {
            $groupSlug = $meta['slug'] ?? 'unknown';
            $grouped[$groupSlug][] = $meta;
        }

        $removed = [];

        foreach ($grouped as $groupSlug => $items) {
            \usort($items, static function (array $a, array $b): int {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            $eligible = [];

            foreach ($items as $index => $meta) {
                $ageOk = true;
                if ($olderThanSeconds !== null) {
                    $createdAt = isset($meta['created_at']) ? strtotime($meta['created_at']) : false;
                    if ($createdAt === false) {
                        $createdAt = @filemtime($meta['path'] ?? '') ?: $now;
                    }

                    if (($now - $createdAt) < $olderThanSeconds) {
                        $ageOk = false;
                    }
                }

                $keepOk = true;
                if ($keep !== null && $index < $keep) {
                    $keepOk = false;
                }

                if ($ageOk && $keepOk) {
                    $eligible[] = $meta;
                }
            }

            foreach ($eligible as $meta) {
                $removed[] = $meta;

                if ($dryRun) {
                    continue;
                }

                $path = $meta['path'] ?? null;
                if ($path !== null && \is_file($path)) {
                    @\unlink($path);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'removed' => $removed,
            'count' => \count($removed),
        ];
    }

    public function restoreBrain(string $backup, ?string $targetSlug = null, bool $activate = false, bool $overwrite = false): array
    {
        $backupPath = $this->resolveBackupPath($backup);

        if (!\is_file($backupPath)) {
            throw new StorageException(\sprintf('Backup file "%s" not found.', $backup));
        }

        $metadata = $this->backupMetadataFromPath($backupPath);
        if ($metadata === null) {
            throw new StorageException('Backup filename is not recognised.');
        }

        $sourceSlug = $metadata['slug'];
        $targetSlug = $targetSlug !== null && $targetSlug !== ''
            ? $this->sanitizeBrainSlug($targetSlug)
            : $sourceSlug;

        $isSystem = strtolower($sourceSlug) === 'system';

        if ($isSystem && $targetSlug !== 'system') {
            throw new StorageException('System brain backups can only be restored to the system brain.');
        }

        if ($targetSlug === 'system' && !$isSystem) {
            throw new StorageException('User brain backups cannot be restored into the system brain.');
        }

        $destination = $isSystem ? $this->paths->systemBrain() : $this->paths->userBrain($targetSlug);

        if (!$overwrite && \is_file($destination)) {
            throw new StorageException(\sprintf('Brain "%s" already exists. Use --overwrite=1 to replace it.', $targetSlug));
        }

        $tempSource = $backupPath;
        $tempCreated = false;

        if (!empty($metadata['compressed'])) {
            $tempSource = $this->decompressBackupToTemp($backupPath);
            $tempCreated = true;
        }

        if (!@\copy($tempSource, $destination)) {
            if ($tempCreated) {
                @\unlink($tempSource);
            }
            throw new StorageException('Failed to restore brain backup.');
        }

        if ($tempCreated) {
            @\unlink($tempSource);
        }

        $this->events->emit('brain.backup.restored', [
            'backup' => $backupPath,
            'target' => $targetSlug,
            'activate' => $activate,
            'overwrite' => $overwrite,
        ]);

        AavionDB::debugLog('Brain backup restored.', [
            'backup' => $backupPath,
            'target' => $targetSlug,
            'activate' => $activate,
            'overwrite' => $overwrite,
            'source' => 'storage:brain',
        ]);

        if ($isSystem) {
            $this->systemBrain = null;
            $this->ensureSystemBrain();
        } else {
            $this->activeBrainData = null;
            $this->activeBrainPath = null;
        }

        if ($activate && !$isSystem) {
            $this->setActiveBrain($targetSlug);
        }

        return $this->brainReport($targetSlug);
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

        AavionDB::debugLog('Writing brain file.', [
            'path' => $path,
            'attempt' => $attempt + 1,
            'source' => 'storage:brain',
        ]);

        $json = CanonicalJson::encode($data);
        $expectedHash = \hash('sha256', $json);
        $tmpPath = $this->writeAtomicTemporary($directory, \basename($path), $json);

        if (!@\chmod($tmpPath, 0664)) {
            // Non-fatal; permissions may vary depending on umask.
        }

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);
            AavionDB::debugLog('Renaming brain file failed.', [
                'path' => $path,
                'tmp' => $tmpPath,
                'attempt' => $attempt + 1,
                'source' => 'storage:brain',
            ]);
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

            AavionDB::debugLog('Brain write verification failed, retrying.', [
                'path' => $path,
                'attempt' => $attempt + 1,
                'context' => $verification['context'],
                'source' => 'storage:brain',
            ]);

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

            AavionDB::debugLog('Brain file written successfully.', [
                'path' => $path,
                'hash' => $expectedHash,
                'attempts' => $attempt + 1,
                'source' => 'storage:brain',
            ]);
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
            'export' => [
                'presets' => [],
                'layouts' => [],
            ],
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

        if (!isset($merged['export']) || !\is_array($merged['export'])) {
            $merged['export'] = [
                'presets' => [],
                'layouts' => [],
            ];
        }

        if (!isset($merged['export']['presets']) || !\is_array($merged['export']['presets'])) {
            $merged['export']['presets'] = [];
        }

        if (!isset($merged['export']['layouts']) || !\is_array($merged['export']['layouts'])) {
            $merged['export']['layouts'] = [];
        }

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
            'hierarchy' => [
                'parents' => [],
                'children' => [],
            ],
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
            'fieldset_version' => $entity['fieldset_version'] ?? null,
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
    /**
     * @return array{payload: array<string, mixed>, version: string, commit: ?string}
     */
    private function resolveSchemaDefinition(string $fieldset, ?string $reference = null): array
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

        $version = isset($record['version']) ? (string) $record['version'] : '1';
        $commit = isset($record['commit']) ? (string) $record['commit'] : null;

        return [
            'payload' => $payload,
            'version' => $version,
            'commit' => $commit,
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function &ensureExportSection(array &$brain): array
    {
        if (!isset($brain['export']) || !\is_array($brain['export'])) {
            $brain['export'] = [
                'presets' => [],
                'layouts' => [],
            ];
        }

        if (!isset($brain['export']['presets']) || !\is_array($brain['export']['presets'])) {
            $brain['export']['presets'] = [];
        }

        if (!isset($brain['export']['layouts']) || !\is_array($brain['export']['layouts'])) {
            $brain['export']['layouts'] = [];
        }

        return $brain['export'];
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

    /**
     * @param array<string, mixed> $brain
     *
     * @return array<string, array<string, mixed>>
     */
    private function resolveProjectTargets(array $brain, ?string $projectSlug, bool $writeAccess): array
    {
        $projects = $brain['projects'] ?? [];
        if (!\is_array($projects)) {
            $projects = [];
        }

        if ($projectSlug !== null) {
            $slug = $this->normalizeKey($projectSlug);

            if (!isset($projects[$slug]) || !\is_array($projects[$slug])) {
                throw new StorageException(\sprintf('Project "%s" not found in active brain.', $projectSlug));
            }

            if ($writeAccess) {
                $this->assertWriteAllowed($slug);
            } else {
                $this->assertReadAllowed($slug);
            }

            return [$slug => $projects[$slug]];
        }

        $targets = [];
        foreach ($projects as $slug => $project) {
            if (!\is_array($project)) {
                continue;
            }

            if ($writeAccess) {
                $this->assertWriteAllowed($slug);
            } else {
                $this->assertReadAllowed($slug);
            }

            $targets[$slug] = $project;
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $brain
     */
    private function removeCommitReference(array &$brain, string $project, string $entity, string $versionKey, ?array $record = null): int
    {
        if (!isset($brain['commits']) || !\is_array($brain['commits'])) {
            $brain['commits'] = [];
        }

        $removed = 0;

        if ($record !== null && isset($record['commit']) && \is_string($record['commit']) && $record['commit'] !== '') {
            if (isset($brain['commits'][$record['commit']])) {
                unset($brain['commits'][$record['commit']]);
                ++$removed;
            }
        }

        foreach ($brain['commits'] as $hash => $commitMeta) {
            if (!\is_array($commitMeta)) {
                continue;
            }

            if (($commitMeta['project'] ?? null) === $project
                && ($commitMeta['entity'] ?? null) === $entity
                && (string) ($commitMeta['version'] ?? '') === (string) $versionKey
            ) {
                if (isset($brain['commits'][$hash])) {
                    unset($brain['commits'][$hash]);
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $brain
     */
    private function findCommitHashForVersion(array $brain, string $project, string $entity, string $version): ?string
    {
        if (!isset($brain['commits']) || !\is_array($brain['commits'])) {
            return null;
        }

        foreach ($brain['commits'] as $hash => $commitMeta) {
            if (!\is_array($commitMeta)) {
                continue;
            }

            if (($commitMeta['project'] ?? null) === $project
                && ($commitMeta['entity'] ?? null) === $entity
                && (string) ($commitMeta['version'] ?? '') === (string) $version
            ) {
                return (string) $hash;
            }
        }

        return null;
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

    private function hierarchyMaxDepth(): int
    {
        $configured = null;

        if (isset($this->options['hierarchy']['max_depth'])) {
            $configured = (int) $this->options['hierarchy']['max_depth'];
        } else {
            $config = $this->getConfigValue('hierarchy.max_depth', null, true);
            if ($config !== null) {
                $configured = (int) $config;
            }
        }

        if ($configured === null || $configured <= 0) {
            return self::DEFAULT_HIERARCHY_MAX_DEPTH;
        }

        return $configured;
    }

    /**
     * @return array{parents: array<string,string>, children: array<string,array<int,string>>}
     */
    private function &ensureProjectHierarchy(array &$brain, string $projectSlug): array
    {
        if (!isset($brain['projects'][$projectSlug]['hierarchy']) || !\is_array($brain['projects'][$projectSlug]['hierarchy'])) {
            $brain['projects'][$projectSlug]['hierarchy'] = [
                'parents' => [],
                'children' => [],
            ];
        }

        $hierarchy =& $brain['projects'][$projectSlug]['hierarchy'];

        if (!isset($hierarchy['parents']) || !\is_array($hierarchy['parents'])) {
            $hierarchy['parents'] = [];
        }

        if (!isset($hierarchy['children']) || !\is_array($hierarchy['children'])) {
            $hierarchy['children'] = [];
        }

        return $hierarchy;
    }

    private function assignEntityParent(array &$brain, string $projectSlug, string $entitySlug, ?string $parentSlug): void
    {
        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);

        $oldParent = $hierarchy['parents'][$entitySlug] ?? null;

        if ($oldParent !== null) {
            $children = $hierarchy['children'][$oldParent] ?? [];
            $children = array_values(array_filter($children, static fn ($child) => $child !== $entitySlug));
            if ($children === []) {
                unset($hierarchy['children'][$oldParent]);
            } else {
                $hierarchy['children'][$oldParent] = $children;
            }
        }

        if ($parentSlug === null || $parentSlug === '') {
            unset($hierarchy['parents'][$entitySlug]);
            return;
        }

        $hierarchy['parents'][$entitySlug] = $parentSlug;

        $siblings = $hierarchy['children'][$parentSlug] ?? [];
        $siblings[] = $entitySlug;
        $hierarchy['children'][$parentSlug] = array_values(array_unique($siblings));
    }

    private function hierarchyParent(array $hierarchy, string $entitySlug): ?string
    {
        return isset($hierarchy['parents'][$entitySlug]) ? (string) $hierarchy['parents'][$entitySlug] : null;
    }

    private function hierarchyChildren(array $hierarchy, string $entitySlug): array
    {
        return isset($hierarchy['children'][$entitySlug]) && \is_array($hierarchy['children'][$entitySlug])
            ? array_values($hierarchy['children'][$entitySlug])
            : [];
    }

    private function collectDescendants(array $hierarchy, string $entitySlug): array
    {
        $result = [];
        $queue = [$entitySlug];

        while ($queue !== []) {
            $current = array_shift($queue);
            $children = $this->hierarchyChildren($hierarchy, $current);

            foreach ($children as $child) {
                if (!in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $result;
    }

    private function wouldCreateCycle(array $hierarchy, string $entitySlug, string $candidateParent): bool
    {
        if ($candidateParent === $entitySlug) {
            return true;
        }

        $descendants = $this->collectDescendants($hierarchy, $entitySlug);

        return in_array($candidateParent, $descendants, true);
    }

    private function resolveParentPath(array &$brain, string $projectSlug, string $entitySlug, array $pathSegments): array
    {
        $warnings = [];
        $applied = [];

        $maxDepth = $this->hierarchyMaxDepth();
        if ($pathSegments !== [] && \count($pathSegments) > $maxDepth) {
            $pathSegments = array_slice($pathSegments, -$maxDepth);
            $warnings[] = sprintf('Hierarchy depth exceeds %d; truncated to deepest allowed path.', $maxDepth);
        }

        $project = $brain['projects'][$projectSlug] ?? null;
        if (!\is_array($project)) {
            return [
                'parent' => null,
                'path' => [],
                'warnings' => array_merge($warnings, ['Project not found; entity will be placed at root.']),
            ];
        }

        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);

        foreach ($pathSegments as $segment) {
            $parentSlug = $this->normalizeKey($segment);

            if ($parentSlug === '' || $parentSlug === $entitySlug) {
                $warnings[] = sprintf('Cannot use "%s" as parent.', $segment);
                break;
            }

            if (!isset($project['entities'][$parentSlug])) {
                $warnings[] = sprintf('Parent "%s" does not exist; entity will be placed at the deepest valid level.', $segment);
                break;
            }

            if ($this->wouldCreateCycle($hierarchy, $entitySlug, $parentSlug)) {
                $warnings[] = sprintf('Parent "%s" would create a cycle; ignoring.', $segment);
                break;
            }

            $applied[] = $parentSlug;
        }

        $parent = $applied !== [] ? end($applied) : null;

        return [
            'parent' => $parent,
            'path' => $applied,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, string> $pathSegments
     */
    private function resolveHierarchyPath(array $brain, string $projectSlug, array $pathSegments): string
    {
        if ($pathSegments === []) {
            throw new StorageException('Hierarchy path cannot be empty.');
        }

        $project = $brain['projects'][$projectSlug] ?? null;
        if (!\is_array($project) || !isset($project['entities']) || !\is_array($project['entities'])) {
            throw new StorageException(sprintf('Project "%s" not found in active brain.', $projectSlug));
        }

        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);
        $currentParent = null;
        $resolved = null;

        foreach ($pathSegments as $segment) {
            $candidate = $this->normalizeKey($segment);

            if (!isset($project['entities'][$candidate]) || !\is_array($project['entities'][$candidate])) {
                throw new StorageException(sprintf('Hierarchy segment "%s" does not exist in project "%s".', $segment, $projectSlug));
            }

            $actualParent = $this->hierarchyParent($hierarchy, $candidate);
            if ($actualParent !== $currentParent) {
                if ($currentParent === null) {
                    throw new StorageException(sprintf('Hierarchy segment "%s" is not a root-level entity.', $segment));
                }

                throw new StorageException(sprintf('Hierarchy segment "%s" is not a child of "%s".', $segment, $currentParent));
            }

            $currentParent = $candidate;
            $resolved = $candidate;
        }

        if ($resolved === null) {
            throw new StorageException('Unable to resolve hierarchy path.');
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $currentParentPath
     * @param array<int, string> $targetParentPath
     * @param array<string, mixed> $options
     */
    public function moveEntity(string $projectSlug, string $entitySlug, array $currentParentPath, array $targetParentPath, array $options = []): array
    {
        $slugProject = $this->normalizeKey($projectSlug);
        $slugEntity = $this->normalizeKey($entitySlug);

        $mode = strtolower((string) ($options['mode'] ?? 'merge'));
        if (!in_array($mode, ['merge', 'replace'], true)) {
            throw new StorageException(sprintf('Unsupported move mode "%s".', $mode));
        }

        $this->assertWriteAllowed($slugProject);

        $brain = $this->loadActiveBrain();
        $project = $this->getProject($slugProject);

        if (!isset($project['entities'][$slugEntity]) || !\is_array($project['entities'][$slugEntity])) {
            throw new StorageException(sprintf('Entity "%s" not found in project "%s".', $entitySlug, $projectSlug));
        }

        $hierarchy =& $this->ensureProjectHierarchy($brain, $slugProject);
        $actualPath = $this->buildEntityPath($hierarchy, $slugEntity);

        $normalizedCurrent = array_map(fn (string $segment): string => $this->normalizeKey($segment), $currentParentPath);
        if ($actualPath !== $normalizedCurrent) {
            throw new StorageException(sprintf(
                'Entity "%s" does not match the provided source path.',
                $entitySlug
            ));
        }

        $currentParent = $this->hierarchyParent($hierarchy, $slugEntity);

        $normalizedTarget = array_map(fn (string $segment): string => $this->normalizeKey($segment), $targetParentPath);
        $targetParent = null;
        if ($normalizedTarget !== []) {
            $targetParent = $this->resolveHierarchyPath($brain, $slugProject, $normalizedTarget);
        }

        if ($targetParent === $slugEntity) {
            throw new StorageException('Entity cannot be its own parent.');
        }

        $descendants = $this->collectDescendants($hierarchy, $slugEntity);
        if ($targetParent !== null && in_array($targetParent, $descendants, true)) {
            throw new StorageException(sprintf(
                'Cannot move entity "%s" beneath its own descendant "%s".',
                $entitySlug,
                $targetParent
            ));
        }

        if ($currentParent === $targetParent) {
            return [
                'project' => $slugProject,
                'entity' => $slugEntity,
                'mode' => $mode,
                'old_parent' => $currentParent,
                'new_parent' => $targetParent,
                'old_path' => $actualPath,
                'new_path' => $actualPath,
                'descendants' => $descendants,
                'warnings' => [],
                'changed' => false,
            ];
        }

        $this->assignEntityParent($brain, $slugProject, $slugEntity, $targetParent);

        $warnings = [];
        if ($mode === 'replace') {
            $warnings[] = 'Replace mode currently behaves like merge; no target pruning applied.';
        }

        $timestamp = $this->timestamp();
        $brain['projects'][$slugProject]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;

        $this->activeBrainData = $brain;
        $this->persistActiveBrain();

        $hierarchy =& $this->ensureProjectHierarchy($brain, $slugProject);
        $newPath = $this->buildEntityPath($hierarchy, $slugEntity);

        $this->events->emit('brain.entity.moved', [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'mode' => $mode,
            'old_parent' => $currentParent,
            'new_parent' => $targetParent,
            'old_path' => $actualPath,
            'new_path' => $newPath,
        ]);

        return [
            'project' => $slugProject,
            'entity' => $slugEntity,
            'mode' => $mode,
            'old_parent' => $currentParent,
            'new_parent' => $targetParent,
            'old_path' => $actualPath,
            'old_path_string' => $actualPath === [] ? null : implode('/', $actualPath),
            'new_path' => $newPath,
            'new_path_string' => $newPath === [] ? null : implode('/', $newPath),
            'descendants' => $descendants,
            'warnings' => $warnings,
            'changed' => true,
        ];
    }

    private function promoteChildren(array &$brain, string $projectSlug, string $entitySlug, ?string $newParent = null): void
    {
        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);
        $children = $this->hierarchyChildren($hierarchy, $entitySlug);

        foreach ($children as $child) {
            $this->assignEntityParent($brain, $projectSlug, $child, $newParent);
        }
    }

    /**
     * @return array{reactivated: bool, active_version: ?string, reason: ?string}
     */
    private function reactivateEntityRecord(array &$entity, string $timestamp): array
    {
        if (!isset($entity['versions']) || !\is_array($entity['versions']) || $entity['versions'] === []) {
            $entity['status'] = 'inactive';
            $entity['archived_at'] = null;
            $entity['updated_at'] = $timestamp;

            return [
                'reactivated' => false,
                'active_version' => null,
                'reason' => 'no_versions',
            ];
        }

        $target = $entity['active_version'] ?? null;
        if ($target === null || !isset($entity['versions'][$target])) {
            $keys = array_keys($entity['versions']);
            \rsort($keys, SORT_NUMERIC);
            $target = (string) ($keys[0] ?? null);
        }

        if ($target === null) {
            $entity['status'] = 'inactive';
            $entity['archived_at'] = null;
            $entity['updated_at'] = $timestamp;

            return [
                'reactivated' => false,
                'active_version' => null,
                'reason' => 'no_versions',
            ];
        }

        foreach ($entity['versions'] as $versionKey => &$record) {
            if (!\is_array($record)) {
                continue;
            }

            $record['status'] = ((string) $versionKey === (string) $target) ? 'active' : 'inactive';
        }
        unset($record);

        $entity['active_version'] = (string) $target;
        $entity['status'] = 'active';
        $entity['archived_at'] = null;
        $entity['updated_at'] = $timestamp;

        return [
            'reactivated' => true,
            'active_version' => (string) $target,
            'reason' => null,
        ];
    }

    private function buildEntityPath(array $hierarchy, string $entitySlug): array
    {
        $path = [];
        $current = $entitySlug;

        while (true) {
            $parent = $this->hierarchyParent($hierarchy, $current);
            if ($parent === null) {
                break;
            }

            array_unshift($path, $parent);
            $current = $parent;
        }

        return $path;
    }

    private function removeEntityFromHierarchy(array &$brain, string $projectSlug, string $entitySlug, bool $promoteChildren = true): void
    {
        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);

        $parent = $this->hierarchyParent($hierarchy, $entitySlug);
        if ($parent !== null) {
            $this->assignEntityParent($brain, $projectSlug, $entitySlug, null);
        }

        if ($promoteChildren) {
            foreach ($this->hierarchyChildren($hierarchy, $entitySlug) as $child) {
                $this->assignEntityParent($brain, $projectSlug, $child, null);
            }
        } else {
            foreach ($this->hierarchyChildren($hierarchy, $entitySlug) as $child) {
                // Remove child references entirely; caller will handle follow-up.
                unset($hierarchy['parents'][$child]);
            }
            unset($hierarchy['children'][$entitySlug]);
        }
    }

    private function clearHierarchyEntry(array &$brain, string $projectSlug, string $entitySlug): void
    {
        $hierarchy =& $this->ensureProjectHierarchy($brain, $projectSlug);
        unset($hierarchy['parents'][$entitySlug]);
        unset($hierarchy['children'][$entitySlug]);
        foreach ($hierarchy['children'] as $parentSlug => &$children) {
            $children = array_values(array_filter($children, static fn ($child) => $child !== $entitySlug));
            if ($children === []) {
                unset($hierarchy['children'][$parentSlug]);
            }
        }
        unset($children);
    }

    private function deleteSingleEntity(array &$brain, string $projectSlug, string $entitySlug, bool $purgeCommits): void
    {
        if (!isset($brain['projects'][$projectSlug]['entities'][$entitySlug])) {
            return;
        }

        unset($brain['projects'][$projectSlug]['entities'][$entitySlug]);

        if ($purgeCommits && isset($brain['commits']) && \is_array($brain['commits'])) {
            foreach ($brain['commits'] as $hash => $commit) {
                if (!\is_array($commit)) {
                    continue;
                }

                if (($commit['project'] ?? null) === $projectSlug && ($commit['entity'] ?? null) === $entitySlug) {
                    unset($brain['commits'][$hash]);
                }
            }
        }

        $timestamp = $this->timestamp();
        $brain['projects'][$projectSlug]['updated_at'] = $timestamp;
        $brain['meta']['updated_at'] = $timestamp;
    }

    private function compressBackup(string $path): string
    {
        $destination = $path . '.gz';

        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new StorageException('Unable to read backup for compression.');
        }

        $gz = @gzopen($destination, 'wb9');
        if ($gz === false) {
            fclose($input);
            throw new StorageException('Unable to create compressed backup.');
        }

        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if ($chunk === false) {
                fclose($input);
                gzclose($gz);
                @unlink($destination);
                throw new StorageException('Failed to read backup during compression.');
            }

            if (gzwrite($gz, $chunk) === false) {
                fclose($input);
                gzclose($gz);
                @unlink($destination);
                throw new StorageException('Failed to write compressed backup.');
            }
        }

        fclose($input);
        gzclose($gz);

        @unlink($path);

        return $destination;
    }

    private function decompressBackupToTemp(string $path): string
    {
        $input = @gzopen($path, 'rb');
        if ($input === false) {
            throw new StorageException('Unable to open compressed backup.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'aaviondb-restore-');
        if ($temp === false) {
            gzclose($input);
            throw new StorageException('Unable to allocate temporary file for restore.');
        }

        $output = @fopen($temp, 'wb');
        if ($output === false) {
            gzclose($input);
            @unlink($temp);
            throw new StorageException('Unable to write temporary restore file.');
        }

        while (!gzeof($input)) {
            $chunk = gzread($input, 8192);
            if ($chunk === false) {
                gzclose($input);
                fclose($output);
                @unlink($temp);
                throw new StorageException('Failed to read compressed backup.');
            }

            if (fwrite($output, $chunk) === false) {
                gzclose($input);
                fclose($output);
                @unlink($temp);
                throw new StorageException('Failed to write decompressed backup.');
            }
        }

        gzclose($input);
        fclose($output);

        return $temp;
    }

    private function resolveBackupPath(string $identifier): string
    {
        $base = $this->paths->userBackups();

        if (\is_file($identifier)) {
            return $identifier;
        }

        $candidate = $base . DIRECTORY_SEPARATOR . ltrim($identifier, DIRECTORY_SEPARATOR);
        if (\is_file($candidate)) {
            return $candidate;
        }

        return $identifier;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backupMetadataFromPath(string $path): ?array
    {
        $filename = basename($path);

        if (!\preg_match('/^(?P<slug>[a-z0-9_.-]+)(?:--(?P<label>[a-z0-9_.-]+))?-(?P<timestamp>\d{8}_\d{6})\.brain(?P<ext>\.gz)?$/', $filename, $matches)) {
            return null;
        }

        $createdAt = DateTimeImmutable::createFromFormat('Ymd_His', $matches['timestamp']);
        $createdIso = $createdAt !== false ? $createdAt->format(DATE_ATOM) : null;

        if ($createdIso === null) {
            $createdIso = date(DATE_ATOM, @filemtime($path) ?: time());
        }

        $bytes = @filesize($path) ?: null;

        return [
            'slug' => $this->sanitizeBrainSlug($matches['slug']),
            'label' => isset($matches['label']) && $matches['label'] !== '' ? $matches['label'] : null,
            'timestamp' => $matches['timestamp'],
            'created_at' => $createdIso,
            'bytes' => $bytes,
            'path' => $path,
            'filename' => $filename,
            'compressed' => isset($matches['ext']) && $matches['ext'] === '.gz',
        ];
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
