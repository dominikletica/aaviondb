<?php

declare(strict_types=1);

namespace AavionDB\Modules\Brain;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function in_array;
use function sprintf;
use function strpos;
use function strtolower;
use function trim;
use function str_starts_with;

final class BrainAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerBrainsCommand();
        $this->registerBrainInitCommand();
        $this->registerBrainSwitchCommand();
        $this->registerBrainBackupCommand();
        $this->registerBrainBackupsCommand();
        $this->registerBrainBackupPruneCommand();
        $this->registerBrainInfoCommand();
        $this->registerBrainValidateCommand();
        $this->registerBrainDeleteCommand();
        $this->registerBrainCleanupCommand();
        $this->registerBrainCompactCommand();
        $this->registerBrainRepairCommand();
        $this->registerBrainRestoreCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('brain', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('brains');
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'init':
                    $context->setAction('brain init');
                    break;
                case 'switch':
                    $context->setAction('brain switch');
                    break;
                case 'backup':
                    if ($tokens !== [] && strtolower(trim((string) $tokens[0])) === 'prune') {
                        array_shift($tokens);
                        $context->setAction('brain backup prune');
                    } else {
                        $context->setAction('brain backup');
                    }
                    break;
                case 'backups':
                    $context->setAction('brain backups');
                    break;
                case 'info':
                    $context->setAction('brain info');
                    break;
                case 'validate':
                    $context->setAction('brain validate');
                    break;
                case 'delete':
                    $context->setAction('brain delete');
                    break;
                case 'compact':
                    $context->setAction('brain compact');
                    break;
                case 'repair':
                    $context->setAction('brain repair');
                    break;
                case 'restore':
                    $context->setAction('brain restore');
                    break;
                case 'cleanup':
                    $context->setAction('brain cleanup');
                    break;
                case 'list':
                    $context->setAction('brains');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('brain info');
                    break;
            }

            $this->injectParameters($context, $tokens, $context->action());
            return;
        }, 10);
    }

    private function injectParameters(ParserContext $context, array $tokens, string $action): void
    {
        $parameters = [];

        $expectSlug = in_array($action, ['brain init', 'brain switch', 'brain backup', 'brain info', 'brain validate', 'brain delete'], true);

        if ($expectSlug && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
            }
        }

        if ($action === 'brain cleanup') {
            if ($tokens !== []) {
                $first = $tokens[0];
                if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                    $parameters['project'] = array_shift($tokens);
                }
            }

            if ($tokens !== []) {
                $next = $tokens[0];
                if (!str_starts_with($next, '--') && strpos($next, '=') === false) {
                    $parameters['entity'] = array_shift($tokens);
                }
            }
        }

        if (in_array($action, ['brain compact', 'brain repair'], true) && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['project'] = array_shift($tokens);
            }
        }

        if ($action === 'brain backups' && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
            }
        }

        if ($action === 'brain backup prune' && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
            }
        }

        if ($action === 'brain restore') {
            if ($tokens !== []) {
                $first = $tokens[0];
                if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                    $parameters['backup'] = array_shift($tokens);
                }
            }

            if ($tokens !== []) {
                $next = $tokens[0];
                if (!str_starts_with($next, '--') && strpos($next, '=') === false) {
                    $parameters['target'] = array_shift($tokens);
                }
            }
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $key = $token;
            $value = true;

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            if (strpos($token, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $token, 2));
            } else {
                $key = $token;
            }

            if ($key === '') {
                continue;
            }

            $key = str_replace('-', '_', $key);
            $parameters[$key] = $value;
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerBrainsCommand(): void
    {
        $this->context->commands()->register('brains', function (array $parameters): CommandResponse {
            return $this->brainsCommand();
        }, [
            'description' => 'List available brains.',
            'group' => 'brain',
            'usage' => 'brains',
        ]);
    }

    private function registerBrainInitCommand(): void
    {
        $this->context->commands()->register('brain init', function (array $parameters): CommandResponse {
            return $this->brainInitCommand($parameters);
        }, [
            'description' => 'Create a new brain and optionally activate it.',
            'group' => 'brain',
            'usage' => 'brain init <slug> [switch=1]',
        ]);
    }

    private function registerBrainSwitchCommand(): void
    {
        $this->context->commands()->register('brain switch', function (array $parameters): CommandResponse {
            return $this->brainSwitchCommand($parameters);
        }, [
            'description' => 'Switch the active brain.',
            'group' => 'brain',
            'usage' => 'brain switch <slug>',
        ]);
    }

    private function registerBrainBackupCommand(): void
    {
        $this->context->commands()->register('brain backup', function (array $parameters): CommandResponse {
            return $this->brainBackupCommand($parameters);
        }, [
            'description' => 'Create a backup copy of a brain.',
            'group' => 'brain',
            'usage' => 'brain backup [slug] [label=name]',
        ]);
    }

    private function registerBrainInfoCommand(): void
    {
        $this->context->commands()->register('brain info', function (array $parameters): CommandResponse {
            return $this->brainInfoCommand($parameters);
        }, [
            'description' => 'Show information about a brain.',
            'group' => 'brain',
            'usage' => 'brain info [slug]',
        ]);
    }

    private function registerBrainValidateCommand(): void
    {
        $this->context->commands()->register('brain validate', function (array $parameters): CommandResponse {
            return $this->brainValidateCommand($parameters);
        }, [
            'description' => 'Run integrity diagnostics for a brain.',
            'group' => 'brain',
            'usage' => 'brain validate [slug]',
        ]);
    }

    private function registerBrainDeleteCommand(): void
    {
        $this->context->commands()->register('brain delete', function (array $parameters): CommandResponse {
            return $this->brainDeleteCommand($parameters);
        }, [
            'description' => 'Permanently delete a brain (cannot be active).',
            'group' => 'brain',
            'usage' => 'brain delete <slug>',
        ]);
    }

    private function registerBrainCleanupCommand(): void
    {
        $this->context->commands()->register('brain cleanup', function (array $parameters): CommandResponse {
            return $this->brainCleanupCommand($parameters);
        }, [
            'description' => 'Purge inactive versions for a project (optional entity filter).',
            'group' => 'brain',
            'usage' => 'brain cleanup <project> [entity]',
        ]);
    }

    private function registerBrainBackupsCommand(): void
    {
        $this->context->commands()->register('brain backups', function (array $parameters): CommandResponse {
            return $this->brainBackupsCommand($parameters);
        }, [
            'description' => 'List stored brain backups (optionally filter by slug).',
            'group' => 'brain',
            'usage' => 'brain backups [slug]',
        ]);
    }

    private function registerBrainBackupPruneCommand(): void
    {
        $this->context->commands()->register('brain backup prune', function (array $parameters): CommandResponse {
            return $this->brainBackupPruneCommand($parameters);
        }, [
            'description' => 'Remove old backups using keep/age policies.',
            'group' => 'brain',
            'usage' => 'brain backup prune <slug|*> [--keep=10] [--older-than=30] [--dry-run=1]',
        ]);
    }

    private function registerBrainCompactCommand(): void
    {
        $this->context->commands()->register('brain compact', function (array $parameters): CommandResponse {
            return $this->brainCompactCommand($parameters);
        }, [
            'description' => 'Rebuild commit indexes and reorder entity versions.',
            'group' => 'brain',
            'usage' => 'brain compact [project] [--dry-run=1]',
        ]);
    }

    private function registerBrainRepairCommand(): void
    {
        $this->context->commands()->register('brain repair', function (array $parameters): CommandResponse {
            return $this->brainRepairCommand($parameters);
        }, [
            'description' => 'Repair entity metadata (active versions, statuses, timestamps).',
            'group' => 'brain',
            'usage' => 'brain repair [project] [--dry-run=1]',
        ]);
    }

    private function registerBrainRestoreCommand(): void
    {
        $this->context->commands()->register('brain restore', function (array $parameters): CommandResponse {
            return $this->brainRestoreCommand($parameters);
        }, [
            'description' => 'Restore a brain from a backup file.',
            'group' => 'brain',
            'usage' => 'brain restore <backup> [target] [--overwrite=0] [--activate=0]',
        ]);
    }

    private function brainsCommand(): CommandResponse
    {
        try {
            $brains = $this->brains->listBrains();

            return CommandResponse::success('brains', [
                'count' => count($brains),
                'brains' => $brains,
            ], 'Available brains');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list brains', ['exception' => $exception]);

            return CommandResponse::error('brains', 'Unable to list brains.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainInitCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('brain init', 'Parameter "slug" is required.');
        }

        if (strtolower($slug) === 'system') {
            return CommandResponse::error('brain init', 'Cannot create a brain with slug "system".');
        }

        $activate = $this->toBool($parameters['switch'] ?? $parameters['activate'] ?? false);

        try {
            $brain = $this->brains->createBrain($slug, $activate);

            return CommandResponse::success('brain init', [
                'brain' => $brain,
            ], $activate ? sprintf('Brain "%s" created and activated.', $brain['slug']) : sprintf('Brain "%s" created.', $brain['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to create brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain init', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainSwitchCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('brain switch', 'Parameter "slug" is required.');
        }

        try {
            $brain = $this->brains->setActiveBrain($slug);

            return CommandResponse::success('brain switch', [
                'brain' => $brain,
            ], sprintf('Active brain set to "%s".', $brain['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to switch brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain switch', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainBackupCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;
        $label = $parameters['label'] ?? null;
        $compress = $this->toBool($parameters['compress'] ?? $parameters['zip'] ?? false);

        try {
            $backup = $this->brains->backupBrain($slug, is_string($label) && $label !== '' ? $label : null, $compress);

            return CommandResponse::success('brain backup', $backup, sprintf('Backup created for brain "%s".', $backup['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to backup brain', [
                'slug' => $slug,
                'compress' => $compress,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain backup', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainInfoCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;

        try {
            $info = $this->brains->brainReport($slug);

            return CommandResponse::success('brain info', $info, sprintf('Brain details for "%s".', $info['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to retrieve brain info', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain info', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainValidateCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;

        try {
            $report = $this->brains->integrityReportFor($slug ?? '');

            return CommandResponse::success('brain validate', $report, 'Integrity report generated.');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to validate brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain validate', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainDeleteCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? strtolower(trim((string) $parameters['slug'])) : '';
        if ($slug === '') {
            return CommandResponse::error('brain delete', 'Parameter "slug" is required.');
        }

        try {
            $result = $this->brains->deleteBrain($slug);

            return CommandResponse::success('brain delete', $result, sprintf('Brain "%s" deleted.', $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain delete', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainCleanupCommand(array $parameters): CommandResponse
    {
        $project = isset($parameters['project']) ? strtolower(trim((string) $parameters['project'])) : '';
        if ($project === '') {
            return CommandResponse::error('brain cleanup', 'Parameter "project" is required.');
        }

        $entity = isset($parameters['entity']) ? strtolower(trim((string) $parameters['entity'])) : null;
        if ($entity === '') {
            $entity = null;
        }

        $keep = 0;
        if (isset($parameters['keep']) && (is_numeric($parameters['keep']) || is_string($parameters['keep']))) {
            $keep = max(0, (int) $parameters['keep']);
        }

        $dryRun = $this->toBool($parameters['dry_run'] ?? $parameters['preview'] ?? false);

        try {
            $result = $this->brains->purgeInactiveEntityVersions($project, $entity, $keep, $dryRun);

            $removedVersions = $result['removed_versions'] ?? 0;
            $removedCommits = $result['removed_commits'] ?? 0;

            $suffix = $entity !== null ? sprintf(' (entity "%s")', $entity) : '';
            $message = $dryRun
                ? sprintf(
                    'Preview: would purge %d inactive version%s and %d commit%s for project "%s"%s.',
                    $removedVersions,
                    $removedVersions === 1 ? '' : 's',
                    $removedCommits,
                    $removedCommits === 1 ? '' : 's',
                    $project,
                    $suffix
                )
                : sprintf(
                    'Purged %d inactive version%s and %d commit%s for project "%s"%s.',
                    $removedVersions,
                    $removedVersions === 1 ? '' : 's',
                    $removedCommits,
                    $removedCommits === 1 ? '' : 's',
                    $project,
                    $suffix
                );

            return CommandResponse::success('brain cleanup', $result, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to cleanup brain versions', [
                'project' => $project,
                'entity' => $entity,
                'dry_run' => $dryRun,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain cleanup', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainBackupsCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? trim((string) $parameters['slug']) : null;
        if ($slug === '' || $slug === '*') {
            $slug = null;
        }

        try {
            $result = $this->brains->listBackups($slug);
            $count = $result['count'] ?? 0;

            $message = $slug !== null
                ? sprintf('Found %d backup%s for brain "%s".', $count, $count === 1 ? '' : 's', $slug)
                : sprintf('Found %d backup%s.', $count, $count === 1 ? '' : 's');

            return CommandResponse::success('brain backups', $result, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list backups', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain backups', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainBackupPruneCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? trim((string) $parameters['slug']) : '*';
        if ($slug === '') {
            $slug = '*';
        }

        $keep = null;
        if (isset($parameters['keep']) && (is_numeric($parameters['keep']) || is_string($parameters['keep']))) {
            $keep = max(0, (int) $parameters['keep']);
        }

        $older = null;
        if (isset($parameters['older_than']) && (is_numeric($parameters['older_than']) || is_string($parameters['older_than']))) {
            $older = max(0, (int) $parameters['older_than']);
        }

        $dryRun = $this->toBool($parameters['dry_run'] ?? $parameters['preview'] ?? false);

        try {
            $result = $this->brains->pruneBackups($slug === '*' ? null : $slug, $keep, $older, $dryRun);
            $count = $result['count'] ?? 0;

            if (!empty($result['skipped'])) {
                $message = 'No backups pruned: supply --keep or --older-than to define retention rules.';
            } else {
                $targetLabel = $slug === '*' ? 'all brains' : sprintf('brain "%s"', $slug);
                $message = $dryRun
                    ? sprintf('Preview: would remove %d backup%s for %s.', $count, $count === 1 ? '' : 's', $targetLabel)
                    : sprintf('Removed %d backup%s for %s.', $count, $count === 1 ? '' : 's', $targetLabel);
            }

            return CommandResponse::success('brain backup prune', $result, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to prune backups', [
                'slug' => $slug,
                'keep' => $keep,
                'older' => $older,
                'dry_run' => $dryRun,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain backup prune', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainRestoreCommand(array $parameters): CommandResponse
    {
        $backup = isset($parameters['backup']) ? trim((string) $parameters['backup']) : '';
        if ($backup === '') {
            return CommandResponse::error('brain restore', 'Parameter "backup" is required.');
        }

        $target = isset($parameters['target']) ? trim((string) $parameters['target']) : null;
        if ($target === '') {
            $target = null;
        }

        $activate = $this->toBool($parameters['activate'] ?? false);
        $overwrite = $this->toBool($parameters['overwrite'] ?? false);

        try {
            $report = $this->brains->restoreBrain($backup, $target, $activate, $overwrite);

            $message = sprintf(
                'Restored backup "%s" into brain "%s"%s.',
                $backup,
                $report['slug'] ?? ($target ?? 'unknown'),
                $activate ? ' and activated it' : ''
            );

            return CommandResponse::success('brain restore', $report, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to restore brain', [
                'backup' => $backup,
                'target' => $target,
                'activate' => $activate,
                'overwrite' => $overwrite,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain restore', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainCompactCommand(array $parameters): CommandResponse
    {
        $project = isset($parameters['project']) ? strtolower(trim((string) $parameters['project'])) : null;
        if ($project === '') {
            $project = null;
        }

        $dryRun = $this->toBool($parameters['dry_run'] ?? $parameters['preview'] ?? false);

        try {
            $result = $this->brains->compactBrain($project, $dryRun);

            $projectLabel = $project ?? 'all projects';
            $projectsProcessed = count($result['projects'] ?? []);
            $commitsRemoved = $result['commits_removed'] ?? 0;
            $commitsAdded = $result['commits_added'] ?? 0;
            $entitiesReordered = $result['entities_reordered'] ?? 0;

            $message = $dryRun
                ? sprintf(
                    'Preview: compaction would affect %d project(s), reorder %d entit%s, remove %d commit%s, add %d commit%s (%s).',
                    $projectsProcessed,
                    $entitiesReordered,
                    $entitiesReordered === 1 ? 'y' : 'ies',
                    $commitsRemoved,
                    $commitsRemoved === 1 ? '' : 's',
                    $commitsAdded,
                    $commitsAdded === 1 ? '' : 's',
                    $projectLabel
                )
                : sprintf(
                    'Compaction completed for %s: %d project(s) processed, %d entit%s reordered, %d commit%s removed, %d commit%s added.',
                    $projectLabel,
                    $projectsProcessed,
                    $entitiesReordered,
                    $entitiesReordered === 1 ? 'y' : 'ies',
                    $commitsRemoved,
                    $commitsRemoved === 1 ? '' : 's',
                    $commitsAdded,
                    $commitsAdded === 1 ? '' : 's'
                );

            return CommandResponse::success('brain compact', $result, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to compact brain', [
                'project' => $project,
                'dry_run' => $dryRun,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain compact', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function brainRepairCommand(array $parameters): CommandResponse
    {
        $project = isset($parameters['project']) ? strtolower(trim((string) $parameters['project'])) : null;
        if ($project === '') {
            $project = null;
        }

        $dryRun = $this->toBool($parameters['dry_run'] ?? $parameters['preview'] ?? false);

        try {
            $result = $this->brains->repairBrain($project, $dryRun);

            $projectLabel = $project ?? 'all projects';
            $projectsProcessed = count($result['projects'] ?? []);
            $entitiesRepaired = $result['entities_repaired'] ?? 0;
            $projectsUpdated = $result['projects_updated'] ?? 0;

            $message = $dryRun
                ? sprintf(
                    'Preview: repair would touch %d project(s) and adjust %d entit%s (%s).',
                    $projectsProcessed,
                    $entitiesRepaired,
                    $entitiesRepaired === 1 ? 'y' : 'ies',
                    $projectLabel
                )
                : sprintf(
                    'Repair completed for %s: %d entit%s adjusted across %d project(s); %d project record(s) updated.',
                    $projectLabel,
                    $entitiesRepaired,
                    $entitiesRepaired === 1 ? 'y' : 'ies',
                    $projectsProcessed,
                    $projectsUpdated
                );

            return CommandResponse::success('brain repair', $result, $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to repair brain', [
                'project' => $project,
                'dry_run' => $dryRun,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain repair', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function extractSlug(array $parameters): ?string
    {
        foreach (['slug', 'brain', 'name'] as $key) {
            if (!isset($parameters[$key])) {
                continue;
            }

            $value = trim((string) $parameters[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
