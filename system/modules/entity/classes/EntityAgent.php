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
use function array_filter;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;
use function strtolower;
use function min;
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
        $this->registerVersionsCommand();
        $this->registerShowCommand();
        $this->registerSaveCommand();
        $this->registerRemoveCommand();
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
                case 'versions':
                    $context->setAction('entity versions');
                    break;
                case 'show':
                    $context->setAction('entity show');
                    break;
                case 'save':
                    $context->setAction('entity save');
                    break;
                case 'remove':
                    $context->setAction('entity remove');
                    break;
                case 'delete':
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

        $expectProject = in_array($action, ['entity list', 'entity versions', 'entity show', 'entity save', 'entity delete', 'entity restore', 'entity remove'], true);
        $expectEntity = in_array($action, ['entity versions', 'entity show', 'entity save', 'entity delete', 'entity restore', 'entity remove'], true);
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

             if (in_array($action, ['entity remove', 'entity delete'], true)
                && !str_starts_with($token, '--')
                && strpos($token, '=') === false) {
                $parameters['entity_extra'][] = $token;
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

    private function registerVersionsCommand(): void
    {
        $this->context->commands()->register('entity versions', function (array $parameters): CommandResponse {
            return $this->entityVersionsCommand($parameters);
        }, [
            'description' => 'List versions for a specific entity within a project.',
            'group' => 'entity',
            'usage' => 'entity versions <project> <entity>',
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

    private function registerRemoveCommand(): void
    {
        $this->context->commands()->register('entity remove', function (array $parameters): CommandResponse {
            return $this->entityRemoveCommand($parameters);
        }, [
            'description' => 'Deactivate the active version of one or more entities.',
            'group' => 'entity',
            'usage' => 'entity remove <project> <entity[,entity2]>',
        ]);
    }

    private function registerDeleteCommand(): void
    {
        $this->context->commands()->register('entity delete', function (array $parameters): CommandResponse {
            return $this->entityDeleteCommand($parameters);
        }, [
            'description' => 'Delete entire entities (all versions) or targeted revisions (@version/#commit).',
            'group' => 'entity',
            'usage' => 'entity delete <project> <entity[@version|#commit][,entity2[@version|#commit]]>',
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

    private function entityVersionsCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity versions');
        $entity = $this->extractEntity($parameters, 'entity versions');

        if ($project === null || $entity === null) {
            return CommandResponse::error('entity versions', 'Parameters "project" and "entity" are required.');
        }

        try {
            $versions = $this->brains->listEntityVersions($project, $entity);

            return CommandResponse::success('entity versions', [
                'project' => $project,
                'entity' => $entity,
                'count' => count($versions),
                'versions' => $versions,
            ], sprintf('Versions for entity "%s" in project "%s".', $entity, $project));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list versions', [
                'project' => $project,
                'entity' => $entity,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity versions', $exception->getMessage(), [
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
        $entityIdentifier = $this->extractEntity($parameters, 'entity save');

        if ($project === null || $entityIdentifier === null) {
            return CommandResponse::error('entity save', 'Parameters "project" and "entity" are required.');
        }

        $entityToken = $entityIdentifier;
        $fieldsetToken = null;

        if (str_contains($entityIdentifier, ':')) {
            [$entityToken, $fieldsetToken] = explode(':', $entityIdentifier, 2);
        }

        $entitySelector = $this->parseSelector($entityToken, false);
        $entity = $entitySelector['slug'];
        $sourceReference = $entitySelector['reference'];

        if ($entity === null || $entity === '') {
            return CommandResponse::error('entity save', 'Entity slug cannot be empty.');
        }

        $fieldsetProvided = false;
        $fieldsetSlug = null;
        $fieldsetReference = null;

        if ($fieldsetToken !== null) {
            $fieldsetProvided = true;
            $inline = $this->parseSelector($fieldsetToken, true);
            $fieldsetSlug = $inline['slug'];
            $fieldsetReference = $inline['reference'];

            if ($fieldsetSlug === null) {
                return CommandResponse::error('entity save', 'Inline fieldset selector is invalid.');
            }

            if ($fieldsetSlug === '' && $fieldsetReference !== null) {
                return CommandResponse::error('entity save', 'Fieldset reference requires a fieldset slug.');
            }
        }

        $payload = $parameters['payload'] ?? null;
        if (!\is_array($payload)) {
            return CommandResponse::error('entity save', 'JSON payload is required (object or array).');
        }

        $meta = [];
        if (isset($parameters['meta']) && \is_array($parameters['meta'])) {
            $meta = $parameters['meta'];
        }

        if (\array_key_exists('fieldset', $parameters)) {
            $fieldsetProvided = true;
            $candidate = $parameters['fieldset'];

            if ($candidate === null || $candidate === '') {
                $fieldsetSlug = '';
                $fieldsetReference = null;
            } elseif (\is_string($candidate)) {
                $parsed = $this->parseSelector($candidate, true);
                $fieldsetSlug = $parsed['slug'];
                $fieldsetReference = $parsed['reference'];

                if ($fieldsetSlug === null) {
                    return CommandResponse::error('entity save', 'Fieldset selector is invalid.');
                }

                if ($fieldsetSlug === '' && $fieldsetReference !== null) {
                    return CommandResponse::error('entity save', 'Fieldset reference requires a fieldset slug.');
                }
            } else {
                return CommandResponse::error('entity save', 'Fieldset selector must be a string or empty.');
            }
        }

        if ($fieldsetProvided && $fieldsetSlug !== null) {
            $fieldsetSlug = strtolower($fieldsetSlug);
        }

        $mergeOption = $parameters['merge'] ?? $parameters['mode'] ?? null;

        try {
            $options = [];

            if ($sourceReference !== null) {
                $options['source_reference'] = $sourceReference;
            }

            if ($fieldsetProvided) {
                $options['fieldset_provided'] = true;
                $options['fieldset'] = $fieldsetSlug;
                if ($fieldsetReference !== null) {
                    $options['fieldset_reference'] = $fieldsetReference;
                }
            }

            if ($mergeOption !== null) {
                $options['merge'] = $mergeOption;
            }

            $commit = $this->brains->saveEntity($project, $entity, $payload, $meta, $options);

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

    private function entityRemoveCommand(array $parameters): CommandResponse
    {
        $project = $this->extractProject($parameters, 'entity remove');
        $rawEntity = $parameters['entity'] ?? null;
        if (isset($parameters['entity_extra'])) {
            $extras = (array) $parameters['entity_extra'];
            $rawEntity = $rawEntity === null ? $extras : $this->combineEntitySelectors($rawEntity, $extras);
        }

        if ($project === null) {
            return CommandResponse::error('entity remove', 'Parameters "project" and "entity" are required.');
        }

        $selectors = $this->expandEntityInputs($rawEntity);
        if ($selectors === []) {
            return CommandResponse::error('entity remove', 'No valid entity selectors supplied.');
        }

        $slugs = [];
        foreach ($selectors as $selector) {
            $slug = $selector['slug'];
            if ($slug === null || $slug === '') {
                continue;
            }

            if ($selector['reference'] !== null) {
                return CommandResponse::error('entity remove', 'Removing specific versions is not supported. Use "delete <project> <entity@version>" instead.');
            }

            $slugs[$slug] = true;
        }

        if ($slugs === []) {
            return CommandResponse::error('entity remove', 'No valid entities detected in selector.');
        }

        try {
            $results = [];
            foreach (\array_keys($slugs) as $slug) {
                $results[] = $this->brains->deactivateEntity($project, $slug);
            }

            return CommandResponse::success('entity remove', [
                'project' => $project,
                'entities' => $results,
            ], sprintf('Deactivated %d entit%s in project "%s".', \count($results), \count($results) === 1 ? 'y' : 'ies', $project));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to remove entity version', [
                'project' => $project,
                'entity' => $rawEntity,
                'exception' => $exception,
            ]);

            return CommandResponse::error('entity remove', $exception->getMessage(), [
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
        $rawEntity = $parameters['entity'] ?? null;
        if (isset($parameters['entity_extra'])) {
            $extras = (array) $parameters['entity_extra'];
            $rawEntity = $rawEntity === null ? $extras : $this->combineEntitySelectors($rawEntity, $extras);
        }
        if ($project === null) {
            return CommandResponse::error('entity delete', 'Parameters "project" and "entity" are required.');
        }

        $selectors = $this->expandEntityInputs($rawEntity);
        if ($selectors === []) {
            return CommandResponse::error('entity delete', 'No valid entity selectors supplied.');
        }

        try {
            $deletedEntities = [];
            $deletedVersions = [];
            $entityLookup = [];

            foreach ($selectors as $selector) {
                $slug = $selector['slug'];
                if ($slug === null || $slug === '') {
                    continue;
                }

                $reference = $selector['reference'];

                if ($reference !== null && $reference !== '') {
                    $deletedVersions[] = $this->brains->deleteEntityVersion($project, $slug, $reference);
                    continue;
                }

                $entityLookup[$slug] = true;
            }

            foreach (\array_keys($entityLookup) as $slug) {
                $this->brains->deleteEntity($project, $slug, true);
                $deletedEntities[] = [
                    'project' => $project,
                    'entity' => $slug,
                    'deleted' => true,
                ];
            }

            return CommandResponse::success('entity delete', [
                'project' => $project,
                'entities' => $deletedEntities,
                'versions' => $deletedVersions,
            ], sprintf(
                'Deleted %d entit%s and %d version%s in project "%s".',
                \count($deletedEntities),
                \count($deletedEntities) === 1 ? 'y' : 'ies',
                \count($deletedVersions),
                \count($deletedVersions) === 1 ? '' : 's',
                $project
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete entity', [
                'project' => $project,
                'entity' => $rawEntity,
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

    /**
     * @return array{slug: ?string, reference: ?string}
     */
    private function expandEntityInputs(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $segments = [];

        if (is_array($value)) {
            foreach ($value as $entry) {
                foreach (explode(',', (string) $entry) as $part) {
                    $segments[] = $part;
                }
            }
        } else {
            $segments = explode(',', (string) $value);
        }

        $selectors = [];

        foreach ($segments as $segment) {
            $trimmed = trim((string) $segment);
            if ($trimmed === '') {
                continue;
            }

            $selector = $this->parseSelector($trimmed, false);
            if ($selector['slug'] === null || $selector['slug'] === '') {
                continue;
            }

            $selectors[] = $selector;
        }

        return $selectors;
    }

    private function combineEntitySelectors(mixed $base, array $extra): array
    {
        $values = [];

        if (is_array($base)) {
            $values = $base;
        } elseif ($base !== null) {
            $values[] = $base;
        }

        foreach ($extra as $entry) {
            $values[] = $entry;
        }

        return $values;
    }

    /**
     * @return array{slug: ?string, reference: ?string}
     */
    private function parseSelector(string $value, bool $allowEmptySlug): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'slug' => $allowEmptySlug ? '' : null,
                'reference' => null,
            ];
        }

        $reference = null;
        $slugPart = $value;

        $positions = array_filter([
            strpos($value, '@'),
            strpos($value, '#'),
        ], static fn ($position) => $position !== false);

        if ($positions !== []) {
            $split = (int) min($positions);
            $slugPart = substr($value, 0, $split);
            $reference = substr($value, $split);
        }

        $slug = strtolower(trim($slugPart));
        if ($slug === '' && !$allowEmptySlug) {
            return [
                'slug' => null,
                'reference' => null,
            ];
        }

        if ($reference !== null) {
            $reference = trim($reference);
            if ($reference === '') {
                $reference = null;
            }
        }

        return [
            'slug' => $slug,
            'reference' => $reference,
        ];
    }
}
