<?php

declare(strict_types=1);

namespace AavionDB\Modules\Project;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_map;
use function array_shift;
use function array_unshift;
use function count;
use function explode;
use function in_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function strtolower;
use function str_starts_with;
use function strpos;
use function trim;

final class ProjectAgent
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
        $this->registerProjectList();
        $this->registerProjectCreate();
        $this->registerProjectRemove();
        $this->registerProjectDelete();
        $this->registerProjectInfo();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('project', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('project list');
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'list':
                    $context->setAction('project list');
                    break;
                case 'create':
                    $context->setAction('project create');
                    break;
                case 'remove':
                case 'archive':
                    $context->setAction('project remove');
                    break;
                case 'delete':
                    $context->setAction('project delete');
                    break;
                case 'info':
                    $context->setAction('project info');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('project info');
                    break;
            }

            $this->injectParameters($context, $tokens);
        }, 10);
    }

    private function injectParameters(ParserContext $context, array $tokens): void
    {
        $parameters = [];

        if ($tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
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

    private function registerProjectList(): void
    {
        $this->context->commands()->register('project list', function (array $parameters): CommandResponse {
            return $this->projectListCommand();
        }, [
            'description' => 'List projects in the active brain.',
            'group' => 'project',
            'usage' => 'project list',
        ]);
    }

    private function registerProjectCreate(): void
    {
        $this->context->commands()->register('project create', function (array $parameters): CommandResponse {
            return $this->projectCreateCommand($parameters);
        }, [
            'description' => 'Create a new project (optionally with title).',
            'group' => 'project',
            'usage' => 'project create <slug> [title="My Project"]',
        ]);
    }

    private function registerProjectRemove(): void
    {
        $this->context->commands()->register('project remove', function (array $parameters): CommandResponse {
            return $this->projectRemoveCommand($parameters);
        }, [
            'description' => 'Archive a project (soft delete).',
            'group' => 'project',
            'usage' => 'project remove <slug>',
        ]);
    }

    private function registerProjectDelete(): void
    {
        $this->context->commands()->register('project delete', function (array $parameters): CommandResponse {
            return $this->projectDeleteCommand($parameters);
        }, [
            'description' => 'Permanently delete a project (dangerous).',
            'group' => 'project',
            'usage' => 'project delete <slug> [purge_commits=1]',
        ]);
    }

    private function registerProjectInfo(): void
    {
        $this->context->commands()->register('project info', function (array $parameters): CommandResponse {
            return $this->projectInfoCommand($parameters);
        }, [
            'description' => 'Show details for a project.',
            'group' => 'project',
            'usage' => 'project info <slug>',
        ]);
    }

    private function projectListCommand(): CommandResponse
    {
        try {
            $projects = $this->brains->listProjects();

            return CommandResponse::success('project list', [
                'count' => count($projects),
                'projects' => array_values($projects),
            ], 'Projects in active brain');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list projects', ['exception' => $exception]);

            return CommandResponse::error('project list', 'Unable to list projects.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function projectCreateCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('project create', 'Parameter "slug" is required.');
        }

        $title = isset($parameters['title']) ? (string) $parameters['title'] : null;

        try {
            $project = $this->brains->createProject($slug, $title);

            return CommandResponse::success('project create', [
                'project' => $project,
            ], sprintf('Project "%s" created.', $project['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to create project', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('project create', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function projectRemoveCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('project remove', 'Parameter "slug" is required.');
        }

        try {
            $project = $this->brains->archiveProject($slug);

            return CommandResponse::success('project remove', [
                'project' => $project,
            ], sprintf('Project "%s" archived.', $project['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to archive project', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('project remove', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function projectDeleteCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('project delete', 'Parameter "slug" is required.');
        }

        $purge = $this->toBool($parameters['purge_commits'] ?? $parameters['purge'] ?? true);

        try {
            $this->brains->deleteProject($slug, $purge);

            return CommandResponse::success('project delete', [
                'slug' => $slug,
                'purged_commits' => $purge,
            ], sprintf('Project "%s" deleted.', $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete project', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('project delete', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function projectInfoCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('project info', 'Parameter "slug" is required.');
        }

        try {
            $info = $this->brains->projectReport($slug);

            return CommandResponse::success('project info', [
                'project' => $info,
            ], sprintf('Project details for "%s".', $info['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch project info', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('project info', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function extractSlug(array $parameters): ?string
    {
        foreach (['slug', 'project'] as $key) {
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
