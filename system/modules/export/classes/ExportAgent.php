<?php

declare(strict_types=1);

namespace AavionDB\Modules\Export;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Filters\FilterEngine;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Resolver\ResolverContext;
use AavionDB\Core\Resolver\ResolverEngine;
use AavionDB\Core\Storage\BrainRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function preg_match;
use function is_string;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function uniqid;

final class ExportAgent
{
    private const DEFAULT_EXPORT_DESCRIPTION = 'Sliced export that contains data to use as context-source (SoT) for the current session.';

    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private FilterEngine $filters;

    private ResolverEngine $resolver;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->filters = new FilterEngine($this->brains, $this->logger);
        $this->resolver = new ResolverEngine($this->brains, $this->logger, $this->filters);
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerExportCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('export', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $parameters = [];

            if ($tokens !== []) {
                $first = $tokens[0];
                if (!$this->looksLikeNamedArgument($first)) {
                    $parameters['project'] = array_shift($tokens);
                }
            }

            $freeTokens = [];
            foreach ($tokens as $token) {
                if ($this->looksLikeNamedArgument($token)) {
                    $parameters = array_merge($parameters, $this->tokenToParameter($token));
                    continue;
                }

                $freeTokens[] = $token;
            }

            if ($freeTokens !== []) {
                $parameters['selectors'] = implode(' ', $freeTokens);
            }

            $context->setAction('export');
            $context->mergeParameters($parameters);
            $context->setTokens([]);
        }, 10);
    }

    private function registerExportCommand(): void
    {
        $this->context->commands()->register('export', function (array $parameters): CommandResponse {
            return $this->exportCommand($parameters);
        }, [
            'description' => 'Generate JSON exports for projects or the entire brain.',
            'group' => 'export',
            'usage' => 'export <project|*> [entity[,entity[@version|#commit]]] [description="How to use this export"]',
        ]);
    }

    private function exportCommand(array $parameters): CommandResponse
    {
        try {
            $input = $this->normaliseInput($parameters);
            $result = $this->generateExport($input);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('export', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Export command failed.', [
                'parameters' => $parameters,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);

            return CommandResponse::error('export', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        return CommandResponse::success('export', $result['payload'], $result['message'], $result['meta']);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function normaliseInput(array $parameters): array
    {
        $targets = $this->normaliseProjectTargets($parameters);
        $presetInline = $this->extractPresetFromTargets($targets);
        $presetParam = isset($parameters['preset']) ? $this->normalisePresetSlug((string) $parameters['preset']) : null;

        if ($presetInline !== null && $presetParam !== null && $presetInline !== $presetParam) {
            throw new InvalidArgumentException(sprintf(
                'Conflicting preset references "%s" and "%s". Use either "<project>:<preset>" or --preset=, not both.',
                $presetInline,
                $presetParam
            ));
        }

        $preset = $presetParam ?? $presetInline;

        // Reset targets to slug-only entries for downstream usage.
        $normalizedTargets = array_map(static fn (array $target): array => ['slug' => $target['slug']], $targets);

        $selectors = $this->parseSelectors($parameters);
        $description = $this->normaliseDescription($parameters['description'] ?? null) ?? self::DEFAULT_EXPORT_DESCRIPTION;
        $usage = $this->normaliseDescription($parameters['usage'] ?? null) ?? $description;
        $params = $this->extractParams($parameters);

        return [
            'targets' => $normalizedTargets,
            'selectors' => $selectors,
            'description' => $description,
            'usage' => $usage,
            'preset' => $preset,
            'params' => $params,
            'raw' => $parameters,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{payload: array<string, mixed>, message: string, meta: array<string, mixed>}
     */
    private function generateExport(array $input): array
    {
        $availableProjects = $this->brains->listProjects();
        $presetSlug = $input['preset'];
        $mode = $presetSlug !== null ? 'preset' : 'manual';
        $selectors = $input['selectors'];
        $params = $input['params'];
        $description = $input['description'];
        $usage = $input['usage'];

        $policies = $this->defaultPolicies();
        $transform = ['whitelist' => [], 'blacklist' => [], 'post' => []];
        $selectionFilters = [];
        $payloadFilters = [];
        $layoutId = self::DEFAULT_LAYOUT;
        $layout = $this->fetchLayout($layoutId);
        $preset = null;

        if ($mode === 'preset') {
            $preset = $this->brains->getPreset($presetSlug ?? '');
            if ($preset === null) {
                throw new InvalidArgumentException(sprintf('Preset "%s" not found.', $presetSlug));
            }

            $layoutId = $preset['meta']['layout'] ?? self::DEFAULT_LAYOUT;
            $layout = $this->fetchLayout($layoutId);
            $transform = $this->prepareTransform($preset['transform'] ?? []);
            $policies = $this->normalisePolicies($preset['policies'] ?? []);
            $selectionFilters = isset($preset['selection']['entities']) && is_array($preset['selection']['entities'])
                ? array_values($preset['selection']['entities'])
                : [];
            $payloadFilters = isset($preset['selection']['payload_filters']) && is_array($preset['selection']['payload_filters'])
                ? array_values($preset['selection']['payload_filters'])
                : [];

            if ($selectors !== []) {
                throw new InvalidArgumentException('Entity selectors cannot be combined with preset-based exports.');
            }
        }

        $projects = $mode === 'preset'
            ? $this->resolveProjectsForPreset($input['targets'], $preset ?? [], $availableProjects, $params)
            : $this->resolveManualProjects($input['targets'], $availableProjects);

        if ($projects === []) {
            throw new InvalidArgumentException('No projects resolved for export.');
        }

        if ($mode === 'manual' && \count($projects) > 1 && $selectors !== []) {
            throw new InvalidArgumentException('Entity selectors are only supported when exporting a single project.');
        }

        $manualTargetMap = [];
        if ($mode === 'manual') {
            $manualTargetMap = $this->groupSelectorsByEntity($selectors);
        }

        $projectSlices = [];
        $entities = [];
        $totalVersions = 0;

        foreach ($projects as $projectSlug) {
            $slice = $this->buildProjectSlice($projectSlug, [
                'mode' => $mode,
                'preset' => $preset,
                'selection_filters' => $selectionFilters,
                'payload_filters' => $payloadFilters,
                'transform' => $transform,
                'params' => $params,
                'manual_targets' => $manualTargetMap,
            ]);

            if ($slice['entities'] === []) {
                continue;
            }

            $projectSlices[] = $slice['project'];
            $entities = array_merge($entities, $slice['entities']);
            $totalVersions += $slice['version_count'];
        }

        if ($projectSlices === []) {
            return [
                'payload' => [],
                'message' => 'No matching entities found for export.',
                'meta' => [
                    'preset' => $presetSlug,
                    'layout' => $layoutId,
                    'scope' => 'empty',
                    'projects' => $projects,
                    'entity_count' => 0,
                    'version_count' => 0,
                ],
            ];
        }

        $stats = $this->buildStats($projectSlices, $entities, $totalVersions);
        $index = $this->buildIndex($projectSlices, $entities);
        $scope = $this->determineScope($projects, $entities, $availableProjects);
        $action = $this->buildActionStringForContext($input, $projects, $presetSlug);
        $timestamp = $this->currentTimestamp();

        $data = [
            'preset' => $presetSlug,
            'generated_at' => $timestamp,
            'scope' => $scope,
            'description' => $description,
            'usage' => $usage,
            'action' => $action,
            'index' => $index,
            'entities' => $entities,
            'stats' => $stats,
            'policies' => $policies,
        ];

        $payload = $this->renderLayout($layout, $data);

        $message = sprintf(
            'Export generated for %d project%s (%d entit%s, %d version%s).',
            count($projectSlices),
            count($projectSlices) === 1 ? '' : 's',
            $stats['entities'],
            $stats['entities'] === 1 ? 'y' : 'ies',
            $stats['versions'],
            $stats['versions'] === 1 ? '' : 's'
        );

        return [
            'payload' => $payload,
            'message' => $message,
            'meta' => [
                'preset' => $presetSlug,
                'layout' => $layout['meta']['id'] ?? $layoutId,
                'scope' => $scope,
                'projects' => array_map(static fn (array $project): string => $project['slug'], $projectSlices),
                'entity_count' => $stats['entities'],
                'version_count' => $stats['versions'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicies(): array
    {
        return [
            'references' => [
                'include' => false,
                'depth' => 0,
            ],
            'cache' => [
                'ttl' => 0,
                'invalidate_on' => ['hash'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLayout(string $layoutId): array
    {
        $layout = $this->brains->getExportLayout($layoutId);
        if ($layout !== null) {
            return $layout;
        }

        return [
            'meta' => [
                'id' => $layoutId,
                'description' => 'Fallback export layout.',
            ],
            'format' => 'json',
            'template' => [
                'meta' => [
                    'layout' => $layoutId,
                    'preset' => '${preset}',
                    'generated_at' => '${generated_at}',
                    'scope' => '${scope}',
                    'description' => '${description}',
                    'action' => '${action}',
                ],
                'guide' => [
                    'usage' => '${usage}',
                ],
                'index' => '${index}',
                'entities' => '${entities}',
                'stats' => '${stats}',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $policies
     *
     * @return array<string, mixed>
     */
    private function normalisePolicies(array $policies): array
    {
        $defaults = $this->defaultPolicies();

        $references = isset($policies['references']) && is_array($policies['references'])
            ? $policies['references']
            : [];

        $cache = isset($policies['cache']) && is_array($policies['cache'])
            ? $policies['cache']
            : [];

        $referenceInclude = isset($references['include']) ? (bool) $references['include'] : $defaults['references']['include'];
        $referenceDepth = isset($references['depth']) && is_numeric($references['depth'])
            ? \max(0, (int) $references['depth'])
            : $defaults['references']['depth'];

        $cacheTtl = isset($cache['ttl']) && is_numeric($cache['ttl'])
            ? \max(0, (int) $cache['ttl'])
            : $defaults['cache']['ttl'];

        $invalidate = $cache['invalidate_on'] ?? $defaults['cache']['invalidate_on'];
        if (is_string($invalidate)) {
            $invalidate = preg_split('/\s*,\s*/', $invalidate) ?: $defaults['cache']['invalidate_on'];
        }
        if (!is_array($invalidate)) {
            $invalidate = $defaults['cache']['invalidate_on'];
        }
        $invalidate = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $invalidate
        ), static fn (string $value): bool => $value !== ''));

        return [
            'references' => [
                'include' => $referenceInclude,
                'depth' => $referenceDepth,
            ],
            'cache' => [
                'ttl' => $cacheTtl,
                'invalidate_on' => $invalidate,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, mixed>             $preset
     * @param array<string, array<string, mixed>> $availableProjects
     * @param array<string, mixed>             $params
     *
     * @return array<int, string>
     */
    private function resolveProjectsForPreset(
        array $targets,
        array $preset,
        array $availableProjects,
        array $params
    ): array {
        $selectors = [];
        if (isset($preset['selection']['projects']) && is_array($preset['selection']['projects'])) {
            $selectors = array_values($preset['selection']['projects']);
        }

        if ($selectors === []) {
            $selectors = ['${project}'];
        }

        $specifiedTargets = $this->resolveManualProjects($targets, $availableProjects);
        $resolved = [];

        foreach ($selectors as $selector) {
            if (!is_string($selector)) {
                continue;
            }

            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }

            if ($selector === '*') {
                return array_keys($availableProjects);
            }

            if (preg_match('/^\$\{(.+)\}$/', $selector, $matches) === 1) {
                $token = $matches[1];
                if ($token === 'project') {
                    if ($specifiedTargets === []) {
                        throw new InvalidArgumentException('Preset expects a project argument but none was provided.');
                    }

                    $resolved = array_merge($resolved, $specifiedTargets);
                    continue;
                }

                if (str_starts_with($token, 'param.')) {
                    $paramName = substr($token, 6);
                    if (!array_key_exists($paramName, $params)) {
                        throw new InvalidArgumentException(sprintf('Preset parameter "%s" is required.', $paramName));
                    }

                    $value = $params[$paramName];
                    $values = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
                    foreach ($values as $entry) {
                        $slug = $this->normalizeProjectSlug((string) $entry);
                        if ($slug !== '') {
                            $resolved[] = $slug;
                        }
                    }

                    continue;
                }

                throw new InvalidArgumentException(sprintf('Unsupported project placeholder "${%s}".', $token));
            }

            $slug = $this->normalizeProjectSlug($selector);
            if ($slug !== '') {
                $resolved[] = $slug;
            }
        }

        if ($resolved === []) {
            $resolved = $specifiedTargets;
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('Preset resolved to no projects.');
        }

        $expanded = [];
        foreach ($resolved as $slug) {
            if ($slug === '*') {
                $expanded = array_keys($availableProjects);
                break;
            }

            if (!isset($availableProjects[$slug])) {
                throw new InvalidArgumentException(sprintf('Project "%s" referenced by the preset does not exist.', $slug));
            }

            $expanded[] = $slug;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, array<string, mixed>> $availableProjects
     *
     * @return array<int, string>
     */
    private function resolveManualProjects(array $targets, array $availableProjects): array
    {
        if ($targets === []) {
            return [];
        }

        $result = [];
        foreach ($targets as $target) {
            $slug = $target['slug'] ?? '';
            if ($slug === '*') {
                return array_keys($availableProjects);
            }

            if (!isset($availableProjects[$slug])) {
                throw new InvalidArgumentException(sprintf('Project "%s" not found.', $slug));
            }

            $result[] = $slug;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupSelectorsByEntity(array $selectors): array
    {
        $map = [];

        foreach ($selectors as $selector) {
            $slug = strtolower($selector['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            if (!isset($map[$slug])) {
                $map[$slug] = [];
            }

            $map[$slug][] = $selector;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{project: array<string, mixed>, entities: array<int, array<string, mixed>>, version_count: int}
     */
    private function buildProjectSlice(string $projectSlug, array $options): array
    {
        $report = $this->brains->projectReport($projectSlug, false);
        $entitiesMeta = $this->brains->listEntities($projectSlug);
        $topology = $this->buildTopology($projectSlug, $entitiesMeta);

        if ($options['mode'] === 'preset') {
            $placeholders = $this->buildPlaceholderMap($options['params'] ?? [], $projectSlug);
            $selection = $this->filters->selectEntities($projectSlug, $options['selection_filters'], [
                'placeholders' => $placeholders,
                'params' => $options['params'] ?? [],
            ]);
            $entitySlugs = $selection['entities'] ?? [];

            if ($options['payload_filters'] !== []) {
                $entitySlugs = array_values(array_filter(
                    $entitySlugs,
                    fn (string $slug): bool => $this->filters->passesFilters(
                        $projectSlug,
                        $slug,
                        $options['payload_filters'],
                        [
                            'placeholders' => $placeholders,
                            'params' => $options['params'] ?? [],
                        ]
                    )
                ));
            }
        } else {
            if (($options['manual_targets'] ?? []) !== []) {
                $entitySlugs = array_keys($options['manual_targets']);
            } else {
                $entitySlugs = array_keys($entitiesMeta);
            }
        }

        $entitySlugs = array_values(array_unique($entitySlugs));

        $entities = [];
        $versionCount = 0;

        foreach ($entitySlugs as $entitySlug) {
            if (!isset($entitiesMeta[$entitySlug])) {
                if ($options['mode'] === 'manual') {
                    throw new InvalidArgumentException(sprintf(
                        'Entity "%s" does not exist in project "%s".',
                        $entitySlug,
                        $projectSlug
                    ));
                }

                continue;
            }

            $selectors = $options['mode'] === 'manual'
                ? ($options['manual_targets'][$entitySlug] ?? [])
                : [];

            $record = $this->buildEntityRecord(
                $projectSlug,
                $entitySlug,
                $selectors,
                $options['transform'] ?? ['whitelist' => [], 'blacklist' => [], 'post' => []],
                $topology,
                [
                    'params' => $options['params'] ?? [],
                ]
            );

            if ($record['entity'] === null) {
                continue;
            }

            $entities[] = $record['entity'];
            $versionCount += $record['version_count'];
        }

        $projectData = [
            'slug' => $report['slug'] ?? $projectSlug,
            'title' => $report['title'] ?? null,
            'description' => $report['description'] ?? null,
            'status' => $report['status'] ?? null,
            'created_at' => $report['created_at'] ?? null,
            'updated_at' => $report['updated_at'] ?? null,
            'archived_at' => $report['archived_at'] ?? null,
            'entity_count' => count($entities),
            'version_count' => $versionCount,
        ];

        return [
            'project' => $projectData,
            'entities' => $entities,
            'version_count' => $versionCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<string, mixed>> $entities
     */
    private function buildStats(array $projects, array $entities, int $versions): array
    {
        return [
            'projects' => count($projects),
            'entities' => count($entities),
            'versions' => $versions,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<string, mixed>> $entities
     *
     * @return array<string, mixed>
     */
    private function buildIndex(array $projects, array $entities): array
    {
        return [
            'projects' => array_map(static function (array $project): array {
                return [
                    'slug' => $project['slug'],
                    'title' => $project['title'] ?? null,
                    'description' => $project['description'] ?? null,
                    'entity_count' => $project['entity_count'] ?? null,
                ];
            }, $projects),
            'entities' => array_map(static function (array $entity): array {
                return [
                    'uid' => $entity['uid'],
                    'project' => $entity['project'],
                    'slug' => $entity['slug'],
                ];
            }, $entities),
        ];
    }

    /**
     * @param array<int, string>                       $projects
     * @param array<int, array<string, mixed>>         $entities
     * @param array<string, array<string, mixed>>      $availableProjects
     */
    private function determineScope(array $projects, array $entities, array $availableProjects): string
    {
        if (count($projects) === count($availableProjects) && count($availableProjects) > 1) {
            return 'brain';
        }

        if (count($projects) === 1) {
            $projectSlug = $projects[0];
            $totalEntities = count($this->brains->listEntities($projectSlug));
            if (count($entities) >= $totalEntities && $totalEntities > 0) {
                return 'project';
            }
        }

        return 'project_slice';
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string>   $projects
     */
    private function buildActionStringForContext(array $input, array $projects, ?string $preset): string
    {
        $projectArgument = $projects === []
            ? ''
            : implode(',', $projects);

        if ($preset !== null) {
            $projectToken = $projectArgument !== '' ? $projectArgument : '(preset)';

            return sprintf('export %s --preset=%s', $projectToken, $preset);
        }

        $selectorStrings = array_map(static fn (array $selector): string => $selector['original'], $input['selectors']);

        return $this->buildActionString($projectArgument, $selectorStrings);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function renderLayout(array $layout, array $data): array
    {
        $template = isset($layout['template']) && is_array($layout['template']) ? $layout['template'] : [];
        $payload = $this->replacePlaceholders($template, $data);

        $entityTemplate = isset($layout['entity_template']) && is_array($layout['entity_template'])
            ? $layout['entity_template']
            : null;

        if ($entityTemplate !== null) {
            $rendered = [];
            foreach ($data['entities'] as $entity) {
                $rendered[] = $this->replacePlaceholders($entityTemplate, ['entity' => $entity] + $data);
            }
            $payload['entities'] = $rendered;
        } else {
            $payload['entities'] = $data['entities'];
        }

        $payload['stats'] = $data['stats'];

        return $payload;
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $data
     *
     * @return mixed
     */
    private function replacePlaceholders($template, array $data)
    {
        if (is_string($template)) {
            $value = trim($template);
            if (preg_match('/^\$\{([a-z0-9._-]+)\}$/i', $value, $matches) === 1) {
                return $this->getValueByPath($data, $matches[1]);
            }

            return $template;
        }

        if (is_array($template)) {
            $result = [];
            foreach ($template as $key => $value) {
                $result[$key] = $this->replacePlaceholders($value, $data);
            }

            return $result;
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, array<int, string>>
     */
    private function buildPlaceholderMap(array $params, string $projectSlug): array
    {
        $map = [
            'project' => [$projectSlug],
        ];

        foreach ($params as $name => $value) {
            $values = is_array($value)
                ? $value
                : (preg_split('/\s*,\s*/', (string) $value) ?: []);
            $values = array_values(array_filter(array_map(
                static fn ($entry): string => trim((string) $entry),
                $values
            ), static fn (string $entry): bool => $entry !== ''));

            if ($values !== []) {
                $map['param.' . $name] = $values;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $entitiesMeta
     *
     * @return array<string, mixed>
     */
    private function buildTopology(string $projectSlug, array $entitiesMeta): array
    {
        $parents = [];
        $children = [];

        foreach ($entitiesMeta as $slug => $meta) {
            $parent = $meta['parent'] ?? null;
            if ($parent === null || $parent === '') {
                $parents[$slug] = null;
                continue;
            }

            $parents[$slug] = $parent;
            if (!isset($children[$parent])) {
                $children[$parent] = [];
            }
            $children[$parent][] = $slug;
        }

        return [
            'parents' => $parents,
            'children' => $children,
            'meta' => $entitiesMeta,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<string, mixed>             $topology
     * @param array<string, mixed>             $options
     *
     * @return array{entity: ?array<string, mixed>, version_count: int}
     */
    private function buildEntityRecord(
        string $projectSlug,
        string $entitySlug,
        array $selectors,
        array $transform,
        array $topology,
        array $options = []
    ): array {
        $summary = $this->brains->entityReport($projectSlug, $entitySlug, true);
        $versionsMeta = isset($summary['versions']) && is_array($summary['versions'])
            ? $summary['versions']
            : [];
        $pathString = isset($summary['path_string']) && is_string($summary['path_string'])
            ? trim($summary['path_string'])
            : null;

        $versionsByNumber = [];
        $versionsByCommit = [];

        foreach ($versionsMeta as $versionMeta) {
            if (!is_array($versionMeta)) {
                continue;
            }

            $versionNumber = (string) ($versionMeta['version'] ?? '');
            if ($versionNumber !== '') {
                $versionsByNumber[$versionNumber] = $versionMeta;
            }

            $commitHash = isset($versionMeta['commit']) ? strtolower((string) $versionMeta['commit']) : '';
            if ($commitHash !== '') {
                $versionsByCommit[$commitHash] = $versionMeta;
            }
        }

        $resolverParams = isset($options['params']) && is_array($options['params'])
            ? $options['params']
            : [];

        $selectedVersions = [];

        if ($selectors === []) {
            $active = (string) ($summary['active_version'] ?? '');
            if ($active !== '' && isset($versionsByNumber[$active])) {
                $selectedVersions[$active] = [
                    'meta' => $versionsByNumber[$active],
                    'lookup' => $active,
                ];
            } elseif ($versionsMeta !== []) {
                $first = $versionsMeta[0];
                $lookup = (string) ($first['version'] ?? ($first['commit'] ?? ''));
                if ($lookup === '') {
                    $lookup = uniqid('', false);
                }

                $selectedVersions[$lookup] = [
                    'meta' => $first,
                    'lookup' => $lookup,
                ];
            }
        } else {
            foreach ($selectors as $selector) {
                $type = $selector['type'] ?? null;
                $reference = (string) ($selector['reference'] ?? '');

                if ($type === 'version') {
                    $meta = $versionsByNumber[$reference] ?? null;
                } elseif ($type === 'commit') {
                    $meta = $versionsByCommit[strtolower($reference)] ?? null;
                } else {
                    $meta = null;
                }

                if ($meta === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Version selector "%s" not found for entity "%s" in project "%s".',
                        $selector['original'],
                        $entitySlug,
                        $projectSlug
                    ));
                }

                $lookup = (string) ($meta['version'] ?? ($meta['commit'] ?? $reference));
                if ($lookup === '') {
                    $lookup = uniqid('', false);
                }

                $selectedVersions[$lookup] = [
                    'meta' => $meta,
                    'lookup' => $lookup,
                ];
            }
        }

        if ($selectedVersions === []) {
            return ['entity' => null, 'version_count' => 0];
        }

        $payloadVersions = [];

        foreach ($selectedVersions as $entry) {
            $meta = $entry['meta'];
            $lookup = $entry['lookup'];
            $record = $this->brains->getEntityVersion($projectSlug, $entitySlug, $lookup);
            $payload = $record['payload'] ?? null;

            if (is_array($payload)) {
                $payload = $this->applyTransform($payload, $transform);
                $resolverContext = new ResolverContext(
                    $projectSlug,
                    $entitySlug,
                    isset($record['version']) ? (string) $record['version'] : null,
                    $resolverParams,
                    $payload,
                    $pathString
                );
                $payload = $this->resolver->resolvePayload($payload, $resolverContext);
            }

            $payloadVersions[] = [
                'version' => (string) ($meta['version'] ?? ''),
                'status' => $meta['status'] ?? 'inactive',
                'hash' => $meta['hash'] ?? null,
                'commit' => $meta['commit'] ?? null,
                'committed_at' => $meta['committed_at'] ?? null,
                'payload' => $payload,
            ];
        }

        $primary = $payloadVersions[0];
        $parentSlug = $topology['parents'][$entitySlug] ?? null;
        $childSlugs = $topology['children'][$entitySlug] ?? [];

        $entity = [
            'uid' => $projectSlug . '.' . $entitySlug,
            'project' => $projectSlug,
            'slug' => $entitySlug,
            'version' => $primary['version'],
            'commit' => $primary['commit'],
            'active' => ($summary['active_version'] ?? null) === $primary['version'],
            'parent' => $parentSlug !== null ? $projectSlug . '.' . $parentSlug : null,
            'children' => array_map(
                static fn (string $child): string => $projectSlug . '.' . $child,
                $childSlugs
            ),
            'refs' => [],
            'payload' => $primary['payload'],
            'payload_versions' => $payloadVersions,
        ];

        return [
            'entity' => $entity,
            'version_count' => count($payloadVersions),
        ];
    }


    /**
     * @param array<int, array<string, mixed>> $targets
     *
     * @return array{project: array<string, mixed>, entity_count: int, version_count: int}
     */
    private function looksLikeNamedArgument(string $token): bool
    {
        return str_starts_with($token, '--') || strpos($token, '=') !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenToParameter(string $token): array
    {
        if (str_starts_with($token, '--')) {
            $token = substr($token, 2);
        }

        $key = $token;
        $value = true;

        if (strpos($token, '=') !== false) {
            [$key, $value] = array_map('trim', explode('=', $token, 2));
        }

        if ($key === '') {
            return [];
        }

        return [$key => $value];
    }

    /**
     * @return array<int, string>
     */
    private function normaliseProjectTargets(array $parameters): array
    {
        $value = $parameters['project'] ?? $parameters['slug'] ?? null;
        if (!is_string($value)) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        if ($value === '*') {
            return [['slug' => '*', 'preset' => null]];
        }

        $segments = preg_split('/\s*,\s*/', $value) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $preset = null;
            if (str_contains($segment, ':')) {
                [$projectPart, $presetPart] = array_map('trim', explode(':', $segment, 2));
                $segment = $projectPart;
                $preset = $presetPart !== '' ? $presetPart : null;
            }

            $slug = $this->normalizeProjectSlug($segment);
            if ($slug === '') {
                continue;
            }

            $normalized[] = [
                'slug' => $slug,
                'preset' => $preset,
            ];
        }

        return $this->uniqueProjectTargets($normalized);
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    private function extractPresetFromTargets(array &$targets): ?string
    {
        $preset = null;

        foreach ($targets as &$target) {
            $candidate = $target['preset'] ?? null;
            unset($target['preset']);

            if ($candidate === null || $candidate === '') {
                continue;
            }

            $slug = $this->normalisePresetSlug($candidate);
            if ($slug === '') {
                continue;
            }

            if ($preset === null) {
                $preset = $slug;
                continue;
            }

            if ($preset !== $slug) {
                throw new InvalidArgumentException(sprintf(
                    'Conflicting preset references "%s" and "%s" detected in project targets.',
                    $preset,
                    $slug
                ));
            }
        }

        return $preset;
    }

    private function normalisePresetSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-_.]/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-_.');

        return $slug === '' ? null : $slug;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function extractParams(array $parameters): array
    {
        $result = [];

        foreach ($parameters as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'param.')) {
                $name = substr($key, 6);
            } elseif (str_starts_with($key, 'params.')) {
                $name = substr($key, 7);
            } elseif (str_starts_with($key, 'var.')) {
                $name = substr($key, 4);
            } elseif (str_starts_with($key, 'vars.')) {
                $name = substr($key, 5);
            } else {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $result[$name] = $value;
        }

        if (isset($parameters['params']) && is_array($parameters['params'])) {
            foreach ($parameters['params'] as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $result[$name] = $value;
            }
        }

        if (isset($parameters['vars']) && is_array($parameters['vars'])) {
            foreach ($parameters['vars'] as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSelectors(array $parameters): array
    {
        $source = $parameters['selectors'] ?? $parameters['entities'] ?? $parameters['entity'] ?? null;

        if ($source === null) {
            return [];
        }

        $segments = [];

        if (is_array($source)) {
            foreach ($source as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $segments = array_merge($segments, $this->splitSelectorString($item));
            }
        } elseif (is_string($source)) {
            $segments = $this->splitSelectorString($source);
        }

        $segments = array_values(array_unique(array_map(static fn ($value) => trim((string) $value), $segments)));

        $selectors = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $selectors[] = $this->parseSelector($segment);
        }

        return $selectors;
    }

    private function splitSelectorString(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $value) ?: [];

        return array_values(array_filter($parts, static fn ($part) => $part !== ''));
    }

    /**
     * @return array{slug: string, selector: ?string, type: ?string, reference: ?string, original: string}
     */
    private function parseSelector(string $selector): array
    {
        $original = $selector;
        $selector = trim($selector);

        if ($selector === '') {
            throw new InvalidArgumentException('Entity selector must not be empty.');
        }

        $prefix = null;
        $reference = null;
        $slug = $selector;

        if (str_contains($selector, '@') || str_contains($selector, '#')) {
            $delimiter = str_contains($selector, '@') ? '@' : '#';
            [$slug, $reference] = array_map('trim', explode($delimiter, $selector, 2));
            $prefix = $delimiter;
        }

        if ($slug === '') {
            throw new InvalidArgumentException(sprintf('Invalid entity selector "%s".', $original));
        }

        if ($prefix !== null && ($reference === null || $reference === '')) {
            throw new InvalidArgumentException(sprintf('Selector "%s" is missing a version/hash value.', $original));
        }

        $type = null;
        if ($prefix === '@') {
            $type = 'version';
        } elseif ($prefix === '#') {
            $type = 'commit';
        }

        return [
            'slug' => strtolower($slug),
            'selector' => $prefix !== null ? $prefix . $reference : null,
            'type' => $type,
            'reference' => $reference,
            'original' => $original,
        ];
    }

    private function normaliseDescription($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function currentTimestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @param array<string, int>               $counts
     * @param array<int, string>               $selectorStrings
     *
     * @return array<string, mixed>
     */


    private function prepareTransform($definition): array
    {
        if (!is_array($definition)) {
            return [
                'whitelist' => [],
                'blacklist' => [],
            ];
        }

        $whitelist = [];
        if (isset($definition['whitelist']) && is_array($definition['whitelist'])) {
            foreach ($definition['whitelist'] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $path = trim($path);
                if ($path !== '') {
                    $whitelist[] = $path;
                }
            }
        }

        $blacklist = [];
        if (isset($definition['blacklist']) && is_array($definition['blacklist'])) {
            foreach ($definition['blacklist'] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $path = trim($path);
                if ($path !== '') {
                    $blacklist[] = $path;
                }
            }
        }

        return [
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
        ];
    }

    private function applyTransform(array $payload, array $transform): array
    {
        $result = $payload;

        $whitelist = $transform['whitelist'] ?? [];
        $blacklist = $transform['blacklist'] ?? [];

        if ($whitelist !== []) {
            $filtered = [];
            foreach ($whitelist as $path) {
                $value = $this->getValueByPath($payload, $path);
                if ($value !== null) {
                    $this->setValueByPath($filtered, $path, $value);
                }
            }
            $result = $filtered;
        }

        if ($blacklist !== []) {
            foreach ($blacklist as $path) {
                $this->removeValueByPath($result, $path);
            }
        }

        return $result;
    }

    private function getValueByPath(array $data, string $path)
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function setValueByPath(array &$data, string $path, $value): void
    {
        $segments = explode('.', $path);
        $current =& $data;

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current =& $current[$segment];
        }

        $current = $value;
    }

    private function removeValueByPath(array &$data, string $path): void
    {
        $segments = explode('.', $path);
        $current =& $data;
        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }

            $current =& $current[$segment];
        }

        unset($current[$lastSegment]);
    }

    private function normalizeProjectSlug(string $slug): string
    {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9\-_.]/', '-', $slug) ?? $slug;
        return trim($slug, '-_.');
    }

    private function uniqueProjectTargets(array $targets): array
    {
        $unique = [];
        $result = [];

        foreach ($targets as $target) {
            $key = $target['slug'] . ':' . ($target['preset'] ?? '');
            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $result[] = $target;
        }

        return $result;
    }

    private function buildActionString(string $projectsArgument, array $selectors): string
    {
        $command = trim('export ' . $projectsArgument);

        if ($selectors !== []) {
            $command .= ' ' . implode(',', $selectors);
        }

        return $command;
    }

}
