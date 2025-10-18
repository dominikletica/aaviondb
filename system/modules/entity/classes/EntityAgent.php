<?php

declare(strict_types=1);

namespace AavionDB\Modules\Entity;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_map;
use function array_shift;
use function array_unshift;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function strtolower;
use function trim;

final class EntityAgent
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
        $this->registerListCommand();
        $this->registerShowCommand();
        $this->registerSaveCommand();
        $this->registerDeleteCommand();
        $this->registerRestoreCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('entity', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('entity list');
                $this->injectParameters($context, 'entity list', $tokens);
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'list':
                    $context->setAction('entity list');
                    break;
                case 'show':
                    $context->setAction('entity show');
                    break;
                case 'save':
                    $context->setAction('entity save');
                    break;
                case 'delete':
                case 'remove':
                    $context->setAction('entity delete');
                    break;
                case 'restore':
                    $context->setAction('entity restore');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('entity show');
                    break;
            }

            $this->injectParameters($context, $context->action(), $tokens);
        }, 10);
    }

    private function injectParameters(ParserContext $context, string $action, array $tokens): void
    {
        $parameters = [];

        $expectProject = in_array($action, ['entity list', 'entity show', 'entity save', 'entity delete', 'entity restore'], true);
        $expectEntity = in_array($action, ['entity show', 'entity save', 'entity delete', 'entity restore'], true);
        $expectReference = in_array($action, ['entity show', 'entity restore'], true);

        if ($expectProject && $tokens !== []) {
            $parameters['project'] = array_shift($tokens);
        }

        if ($expectEntity && $tokens !== []) {
            $parameters['entity'] = array_shift($tokens);
        }

        if ($expectReference && $tokens !== []) {
            $parameters['reference'] = array_shift($tokens);
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            $key = $token;
            $value = true;

            if (strpos($token, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $token, 2));
            }

            if ($key === '') {
                continue;
            }

            $parameters[$key] = $value;
        }

        if ($context->payload() !== null) {
            $parameters['payload'] = $context->payload();
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerListCommand(): void
    {
        $this->context->commands()->register('entity list', function (array $parameters): CommandResponse {
            return $this->entityListCommand($parameters);
        }, [
            'description' => 'List entities inside a project.',
            'group' => 'entity',
            'usage' => 'entity list <project> [with_versions=1]',
        ]);
    }

    private function registerShowCommand(): void
    {
        $this->context->commands()->register('entity show', function (array $parameters): CommandResponse {
            return $this->entityShowCommand($parameters);
        }, [
            'description' => 'Show specific entity version details.',
            'group' => 'entity',
            'usage' => 'entity show <project> <entity> [@version|#commit]',
        ]);
    }

    private function registerSaveCommand(): void
    {
        $this->context->commands()->register('entity save', function (array $parameters): CommandResponse {
            return $this->entitySaveCommand($parameters);
        }, [
            'description' => 'Persist a new version for an entity.',
            'group' => 'entity',
            'usage' => 'entity save <project> <entity> {json payload}',
        ]);
    }

    private function registerDeleteCommand(): void
    {
        $this->context->commands()->register('entity delete', function (array $parameters): CommandResponse {
            return $this->entityDeleteCommand($parameters);
        }, [
            'description' => 'Archive an entity or purge it permanently.',
            'group' => 'entity',
            'usage' => 'entity delete <project> <entity> [purge=0]',
        ]);
    }

    private function registerRestoreCommand(): void
    {
        $this->context->commands()->register('entity restore', function (array $parameters): CommandResponse {
            return $this->entityRestoreCommand($parameters);
        }, [
            'description' => 'Restore an entity to a previous version.',
            'group' => 'entity',
            'usage' => 'entity restore <project> <entity> <@version|#commit>',
        ]);
    }

    private function entityListCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity list');
        if ($project === null) {
            return CommandResponse::error('entity list', 'Parameter "project" is required.');
        }

        $withVersions = $this->toBool($parameters['with_versions'] ?? $parameters['versions'] ?? false);

        try {
            if ($withVersions) {
                $entities = [];
                foreach ($this->brains->listEntities($project) as $slug => $summary) {
                    $summary['versions'] = $this->brains->listEntityVersions($project, $slug);
                    $entities[] = $summary;
                }
            } else {
                $entities = \array_values($this->brains->listEntities($project));
            }

            return CommandResponse::success('entity list', [
                'project' => $project,
                'count' => \count($entities),
                'entities' => $entities,
            ], sprintf('Entities for project "%s".', $project));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list entities', [
                'project' => $project,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity list', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function entityShowCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity show');
        $entity = $this->extractEntity($parameters, 'entity show');
        if ($project === null || $entity === null) {
            return CommandResponse::error('entity show', 'Parameters "project" and "entity" are required.');
        }

        $reference = isset($parameters['reference']) ? (string) $parameters['reference'] : '';

        try {
            $version = $this->brains->getEntityVersion($project, $entity, $reference !== '' ? $reference : null);

            return CommandResponse::success('entity show', [
                'project' => $project,
                'entity' => $entity,
                'version' => $version['version'] ?? null,
                'record' => $version,
            ], sprintf('Entity "%s" in project "%s".', $entity, $project));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to show entity', [
                'project' => $project,
                'entity' => $entity,
                'reference' => $reference,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity show', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function entitySaveCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity save');
        $entity = $this->extractEntity($parameters, 'entity save');
        if ($project === null || $entity === null) {
            return CommandResponse::error('entity save', 'Parameters "project" and "entity" are required.');
        }

        $payload = $parameters['payload'] ?? null;
        if (!\is_array($payload)) {
            return CommandResponse::error('entity save', 'JSON payload is required (object or array).');
        }

        $meta = [];
        if (isset($parameters['meta']) && \is_array($parameters['meta'])) {
            $meta = $parameters['meta'];
        }

        try {
            $commit = $this->brains->saveEntity($project, $entity, $payload, $meta);

            return CommandResponse::success('entity save', $commit, sprintf('Entity "%s" saved (version %s).', $entity, $commit['version'] ?? '')); 
        } catch (Throwable $exception) {
            $this->logger->error('Failed to save entity', [
                'project' => $project,
                'entity' => $entity,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity save', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function entityDeleteCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity delete');
        $entity = $this->extractEntity($parameters, 'entity delete');
        if ($project === null || $entity === null) {
            return CommandResponse::error('entity delete', 'Parameters "project" and "entity" are required.');
        }

        $purge = $this->toBool($parameters['purge'] ?? $parameters['purge_commits'] ?? false);

        try {
            if ($purge) {
                $this->brains->deleteEntity($project, $entity, true);

                return CommandResponse::success('entity delete', [
                    'project' => $project,
                    'entity' => $entity,
                    'purged' => true,
                ], sprintf('Entity "%s" permanently deleted.', $entity));
            }

            $summary = $this->brains->archiveEntity($project, $entity);

            return CommandResponse::success('entity delete', [
                'project' => $project,
                'entity' => $summary,
                'purged' => false,
            ], sprintf('Entity "%s" archived.', $entity));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete entity', [
                'project' => $project,
                'entity' => $entity,
                'purge' => $purge,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity delete', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function entityRestoreCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity restore');
        $entity = $this->extractEntity($parameters, 'entity restore');
        $reference = isset($parameters['reference']) ? (string) $parameters['reference'] : '';

        if ($project === null || $entity === null || $reference === '') {
            return CommandResponse::error('entity restore', 'Parameters "project", "entity", and a reference are required.');
        }

        try {
            $summary = $this->brains->restoreEntityVersion($project, $entity, $reference);

            return CommandResponse::success('entity restore', [
                'project' => $project,
                'entity' => $summary,
            ], sprintf('Entity "%s" restored to %s.', $entity, $summary['active_version'] ?? 'requested version'));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to restore entity', [
                'project' => $project,
                'entity' => $entity,
                'reference' => $reference,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity restore', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function extractProject(array $parameters, string $action): ?string
    {
        if (isset($parameters['project']) && $parameters['project'] !== '') {
            return strtolower(trim((string) $parameters['project']));
        }

        $this->logger->warning('Missing project parameter', ['action' => $action]);

        return null;
    }

    private function extractEntity(array $parameters, string $action): ?string
    {
        if (isset($parameters['entity']) && $parameters['entity'] !== '') {
            return strtolower(trim((string) $parameters['entity']));
        }

        $this->logger->warning('Missing entity parameter', ['action' => $action]);

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
