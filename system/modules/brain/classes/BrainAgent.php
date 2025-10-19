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
        $this->registerBrainInfoCommand();
        $this->registerBrainValidateCommand();
        $this->registerBrainDeleteCommand();
        $this->registerBrainCleanupCommand();
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
                    $context->setAction('brain backup');
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

        try {
            $backup = $this->brains->backupBrain($slug, is_string($label) && $label !== '' ? $label : null);

            return CommandResponse::success('brain backup', $backup, sprintf('Backup created for brain "%s".', $backup['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to backup brain', [
                'slug' => $slug,
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

        try {
            $result = $this->brains->purgeInactiveEntityVersions($project, $entity, $keep);

            return CommandResponse::success('brain cleanup', $result, sprintf(
                'Purged %d inactive version%s for project "%s"%s.',
                $result['removed_versions'] ?? 0,
                ($result['removed_versions'] ?? 0) === 1 ? '' : 's',
                $project,
                $entity !== null ? sprintf(' (entity "%s")', $entity) : ''
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to cleanup brain versions', [
                'project' => $project,
                'entity' => $entity,
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
