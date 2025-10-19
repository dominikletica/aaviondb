<?php

declare(strict_types=1);

namespace AavionDB\Modules\Export;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Hashing\CanonicalJson;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_key_exists;
use function file_get_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_split;
use function reset;
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

    private PathLocator $paths;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $presetCache = [];

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->paths = $context->paths();
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
        $projects = $this->normaliseProjectTargets($parameters);
        if ($projects === []) {
            return CommandResponse::error('export', 'Project slug list (or "*") is required.');
        }

        try {
            $selectors = $this->parseSelectors($parameters);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('export', $exception->getMessage());
        }

        $selectorStrings = array_map(static fn (array $selector): string => $selector['original'], $selectors);

        try {
            $exportDescription = $this->normaliseDescription($parameters['description'] ?? null) ?? self::DEFAULT_EXPORT_DESCRIPTION;
            $guideUsage = $this->normaliseDescription($parameters['usage'] ?? null) ?? $exportDescription;

            $presetTargets = array_filter($projects, static fn (array $target) => $target['preset'] !== null);
            $payloadFilter = null;
            $transform = ['whitelist' => [], 'blacklist' => []];

            if ($presetTargets !== []) {
                if (\count($presetTargets) > 1 || \count($projects) > 1) {
                    return CommandResponse::error('export', 'Presets can only be used when exporting a single project.');
                }

                $presetName = $presetTargets[0]['preset'];
                if ($presetName === null) {
                    return CommandResponse::error('export', 'Invalid preset reference.');
                }

                if ($selectors !== []) {
                    return CommandResponse::error('export', 'Entity selectors cannot be combined with preset-based exports.');
                }

                $presetConfig = $this->loadPreset($presetName);
                $selectors = $this->selectorsFromPreset($presetConfig);
                $selectorStrings = array_map(static fn (array $selector): string => $selector['original'], $selectors);
                $payloadFilter = $this->preparePayloadFilter($presetConfig['selection']['payload'] ?? null);
                $transform = $this->prepareTransform($presetConfig['transform'] ?? []);
                $exportDescription = $this->normaliseDescription($parameters['description'] ?? ($presetConfig['description'] ?? null)) ?? self::DEFAULT_EXPORT_DESCRIPTION;
                $guideUsage = $this->normaliseDescription($parameters['usage'] ?? ($presetConfig['usage'] ?? null)) ?? $exportDescription;
            }

            if (\count($projects) === 1 && $projects[0]['slug'] === '*') {
                if ($presetTargets !== []) {
                    return CommandResponse::error('export', 'Wildcard exports do not support presets.');
                }
                if ($selectors !== []) {
                    return CommandResponse::error('export', 'Entity selectors are not supported when exporting all projects.');
                }

                $availableProjects = $this->brains->listProjects();
                $projectExports = [];
                $totalEntities = 0;
                $totalVersions = 0;

                foreach (array_keys($availableProjects) as $slug) {
                    $export = $this->buildProjectExport($slug, [], null, []);
                    $projectExports[] = $export['project'];
                    $totalEntities += $export['entity_count'];
                    $totalVersions += $export['version_count'];
                }

                $counts = [
                    'projects' => \count($projectExports),
                    'entities' => $totalEntities,
                    'versions' => $totalVersions,
                ];

                $payload = $this->buildPayload(
                    $projectExports,
                    'brain',
                    $counts,
                    $exportDescription,
                    $this->buildActionString('*', []),
                    [],
                    $guideUsage
                );

                $message = $projectExports === []
                    ? 'No projects available for export.'
                    : sprintf('Export generated for %d project(s).', count($projectExports));

                return CommandResponse::success('export', $payload, $message);
            }

            if (\count($projects) > 1 && $selectors !== []) {
                return CommandResponse::error('export', 'Entity selectors are only supported when exporting a single project.');
            }

            $projectExports = [];
            $totalEntities = 0;
            $totalVersions = 0;

            foreach ($projects as $target) {
                if ($target['slug'] === '*') {
                    return CommandResponse::error('export', 'Wildcard export must be the only target.');
                }

                $export = $this->buildProjectExport($target['slug'], $selectors, $payloadFilter, $transform);
                $projectExports[] = $export['project'];
                $totalEntities += $export['entity_count'];
                $totalVersions += $export['version_count'];
            }

            $isSlice = $selectors !== [];
            $scope = $isSlice ? 'project_slice' : (\count($projects) > 1 ? 'projects' : 'project');
            $counts = [
                'projects' => \count($projectExports),
                'entities' => $totalEntities,
                'versions' => $totalVersions,
            ];

            $payload = $this->buildPayload(
                $projectExports,
                $scope,
                $counts,
                $exportDescription,
                $this->buildActionString($this->formatProjectArgument($projects), $selectorStrings),
                $selectorStrings,
                $guideUsage
            );

            $message = \count($projects) === 1
                ? sprintf('Export generated for project "%s".', $projectExports[0]['slug'] ?? $projects[0]['slug'])
                : sprintf('Export generated for %d project(s).', \count($projectExports));

            return CommandResponse::success('export', $payload, $message);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('export', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to generate export.', [
                'project' => $project,
                'selectors' => $selectorStrings,
                'exception' => $exception,
            ]);

            return CommandResponse::error('export', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     *
     * @return array{project: array<string, mixed>, entity_count: int, version_count: int}
     */
    private function buildProjectExport(string $projectSlug, array $targets, ?array $payloadFilter, array $transform): array
    {
        $projectSummary = $this->brains->projectReport($projectSlug, false);
        $entities = $this->brains->listEntities($projectSlug);
        $entitySlugs = array_keys($entities);

        $targetMap = [];
        foreach ($targets as $target) {
            $slug = $target['slug'];
            $targetMap[$slug][] = $target;
        }

        if ($targetMap !== []) {
            foreach (array_keys($targetMap) as $slug) {
                if (!in_array($slug, $entitySlugs, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Entity "%s" does not exist in project "%s".',
                        $slug,
                        $projectSlug
                    ));
                }
            }
            $entitySlugs = array_keys($targetMap);
        }

        $entityExports = [];
        $versionCount = 0;

        foreach ($entitySlugs as $entitySlug) {
            $selections = $targetMap[$entitySlug] ?? [];
            $export = $this->buildEntityExport($projectSlug, $entitySlug, $selections, $payloadFilter, $transform);

            if ($export['version_count'] === 0) {
                continue;
            }

            $entityExports[] = $export['entity'];
            $versionCount += $export['version_count'];
        }

        $projectData = [
            'slug' => $projectSummary['slug'] ?? $projectSlug,
            'title' => $projectSummary['title'] ?? null,
            'description' => $projectSummary['description'] ?? null,
            'status' => $projectSummary['status'] ?? null,
            'created_at' => $projectSummary['created_at'] ?? null,
            'updated_at' => $projectSummary['updated_at'] ?? null,
            'archived_at' => $projectSummary['archived_at'] ?? null,
            'entity_count' => count($entityExports),
            'version_count' => $versionCount,
            'entities' => $entityExports,
        ];

        return [
            'project' => $projectData,
            'entity_count' => count($entityExports),
            'version_count' => $versionCount,
        ];
    }

    /**
     * @return array{entity: array<string, mixed>, version_count: int}
     */
    private function buildEntityExport(string $projectSlug, string $entitySlug, array $targets = [], ?array $payloadFilter = null, array $transform = []): array
    {
        $summary = $this->brains->entityReport($projectSlug, $entitySlug, true);
        $versions = isset($summary['versions']) && is_array($summary['versions'])
            ? $summary['versions']
            : [];

        $versionsByVersion = [];
        $versionsByCommit = [];

        foreach ($versions as $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $versionId = (string) ($meta['version'] ?? '');
            if ($versionId !== '') {
                $versionsByVersion[$versionId] = $meta;
            }

            $commitHash = isset($meta['commit']) ? strtolower((string) $meta['commit']) : '';
            if ($commitHash !== '') {
                $versionsByCommit[$commitHash] = $meta;
            }
        }

        $selected = [];

        $explicitSelections = $targets !== [];
        $applyPayloadFilter = !$explicitSelections && $payloadFilter !== null;

        if ($targets === []) {
            $activeVersion = (string) ($summary['active_version'] ?? '');
            if ($activeVersion !== '' && isset($versionsByVersion[$activeVersion])) {
                $selected[$activeVersion] = [
                    'meta' => $versionsByVersion[$activeVersion],
                    'selectors' => [],
                    'lookup' => $activeVersion,
                ];
            } elseif ($versions !== []) {
                $first = $versions[0];
                $versionKey = (string) ($first['version'] ?? '');
                $lookup = $versionKey !== '' ? $versionKey : (string) ($first['commit'] ?? '');
                if ($versionKey === '' && $lookup === '') {
                    $lookup = uniqid('', false);
                }
                $selected[$versionKey !== '' ? $versionKey : strtolower($lookup)] = [
                    'meta' => $first,
                    'selectors' => [],
                    'lookup' => $lookup,
                ];
            }
        } else {
            foreach ($targets as $target) {
                $type = $target['type'];

                if ($type === 'version') {
                    $reference = (string) $target['reference'];
                    $metaVersion = $versionsByVersion[$reference] ?? null;
                } else {
                    $reference = strtolower((string) $target['reference']);
                    $metaVersion = $versionsByCommit[$reference] ?? null;
                }

                if ($metaVersion === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Version selector "%s" not found for entity "%s" in project "%s".',
                        $target['original'],
                        $entitySlug,
                        $projectSlug
                    ));
                }

                $versionKey = (string) ($metaVersion['version'] ?? '');
                if ($versionKey === '') {
                    $versionKey = strtolower($metaVersion['commit'] ?? $target['original']);
                }

                if (!isset($selected[$versionKey])) {
                    $selected[$versionKey] = [
                        'meta' => $metaVersion,
                        'selectors' => [],
                        'lookup' => $type === 'commit'
                            ? (string) ($metaVersion['commit'] ?? '')
                            : (string) ($metaVersion['version'] ?? ''),
                    ];
                }

                $selected[$versionKey]['selectors'][] = $target['original'];

                if ($type === 'commit' && $selected[$versionKey]['lookup'] === '') {
                    $selected[$versionKey]['lookup'] = (string) ($metaVersion['commit'] ?? '');
                }
            }
        }

        $versionExports = [];
        foreach ($selected as $entry) {
            $meta = $entry['meta'];
            if (!is_array($meta)) {
                continue;
            }

            $lookup = $entry['lookup'] ?? '';
            if ($lookup === '') {
                $lookup = (string) ($meta['version'] ?? '');
            }

            $reference = $lookup === '' ? null : $lookup;
            $record = $this->brains->getEntityVersion($projectSlug, $entitySlug, $reference);
            $selectors = $entry['selectors'] ?? [];

            $payload = $record['payload'] ?? null;

            if ($applyPayloadFilter && !$this->payloadMatchesFilter($payload, $payloadFilter)) {
                continue;
            }

            if (is_array($payload)) {
                $payload = $this->applyTransform($payload, $transform);
            }

            $versionExports[] = [
                'version' => (string) ($meta['version'] ?? ''),
                'status' => $meta['status'] ?? 'inactive',
                'hash' => $meta['hash'] ?? null,
                'commit' => $meta['commit'] ?? null,
                'committed_at' => $meta['committed_at'] ?? null,
                'payload' => $payload,
                'meta' => isset($record['meta']) && is_array($record['meta']) ? $record['meta'] : [],
                'selectors' => $selectors === [] ? null : array_values(array_unique($selectors)),
            ];
        }

        $entitySelectors = array_values(array_unique(array_map(
            static fn (array $target) => $target['original'],
            $targets
        )));

        $entityData = [
            'slug' => $summary['slug'] ?? $entitySlug,
            'status' => $summary['status'] ?? null,
            'created_at' => $summary['created_at'] ?? null,
            'updated_at' => $summary['updated_at'] ?? null,
            'archived_at' => $summary['archived_at'] ?? null,
            'active_version' => $summary['active_version'] ?? null,
            'selectors' => $entitySelectors === [] ? null : $entitySelectors,
            'versions' => $versionExports,
        ];

        return [
            'entity' => $entityData,
            'version_count' => count($versionExports),
        ];
    }

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
    private function buildPayload(
        array $projectItems,
        string $scope,
        array $counts,
        string $description,
        string $action,
        array $selectorStrings,
        string $guideUsage,
        bool $isSlice
    ): array
    {
        $payload = [
            'project' => [
                'items' => $projectItems,
                'count' => \count($projectItems),
            ],
            'export_meta' => [
                'generated_at' => $this->currentTimestamp(),
                'scope' => $scope,
                'action' => $action,
                'counts' => $counts,
                'description' => $description,
            ],
            'guide' => $this->buildGuide($guideUsage, $scope),
            'counts' => $counts,
        ];

        if ($selectorStrings !== []) {
            $payload['filters'] = [
                'entities' => $selectorStrings,
            ];
        }

        $payload['export_meta']['hash'] = $this->hashExport($payload);

        return $payload;
    }

    /**
     * @param array<int, string> $projects
     */
    private function formatProjectArgument(array $projects): string
    {
        if (\count($projects) === 1 && $projects[0]['slug'] === '*') {
            return '*';
        }

        $segments = [];
        foreach ($projects as $target) {
            $segment = $target['slug'];
            if ($target['preset'] !== null) {
                $segment .= ':' . $target['preset'];
            }
            $segments[] = $segment;
        }

        return implode(',', $segments);
    }

    /**
     * @param array<int, string> $selectors
     */
    private function buildActionString(string $projectsArgument, array $selectors): string
    {
        $command = 'export ' . $projectsArgument;

        if ($selectors !== []) {
            $command .= ' ' . implode(',', $selectors);
        }

        return $command;
    }

    private function buildGuide(string $description, string $scope): array
    {
        $navigation = [
            'project.items[*]',
            'project.items[*].entities[*]',
            'project.items[*].entities[*].versions[*]',
        ];

        if ($scope === 'project' || $scope === 'project_slice') {
            $navigation[] = 'project.items[0] (single-project slice)';
        }

        $policies = [
            'cache' => 'Treat versions marked "active" as canonical; invalidate caches when their hash changes.',
            'load' => 'Use selectors (if present) to inspect archived or alternate revisions; otherwise rely on the active version.',
        ];

        $notes = [
            'Timestamps use ISO-8601 (UTC).',
        ];

        return [
            'usage' => $description,
            'navigation' => $navigation,
            'policies' => $policies,
            'notes' => $notes,
        ];
    }

    private function presetDirectory(): string
    {
        $directory = $this->paths->user() . DIRECTORY_SEPARATOR . 'presets' . DIRECTORY_SEPARATOR . 'export';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function sanitizePresetName(string $name): string
    {
        $sanitized = trim($name);
        $sanitized = preg_replace('/[^a-z0-9\-_.]/i', '_', $sanitized) ?? $sanitized;

        return trim($sanitized, '_') ?: 'preset';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPreset(string $name): array
    {
        $cacheKey = strtolower($name);
        if (isset($this->presetCache[$cacheKey])) {
            return $this->presetCache[$cacheKey];
        }

        $fileName = $this->sanitizePresetName($name) . '.json';
        $path = $this->presetDirectory() . DIRECTORY_SEPARATOR . $fileName;

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Preset "%s" not found (expected %s).', $name, $path));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Preset "%s" contains invalid JSON: %s', $name, $exception->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Preset "%s" must decode to an object.', $name));
        }

        return $this->presetCache[$cacheKey] = $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectorsFromPreset(array $preset): array
    {
        $entities = $preset['selection']['entities'] ?? [];
        if (!is_array($entities)) {
            return [];
        }

        $selectors = [];
        foreach ($entities as $raw) {
            if (!is_string($raw)) {
                continue;
            }

            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $selectors[] = $this->parseSelector($raw);
        }

        return $selectors;
    }

    private function preparePayloadFilter($definition): ?array
    {
        if (!is_array($definition)) {
            return null;
        }

        $path = isset($definition['path']) ? trim((string) $definition['path']) : '';
        if ($path === '') {
            return null;
        }

        if (!array_key_exists('equals', $definition)) {
            return null;
        }

        return [
            'path' => $path,
            'equals' => $definition['equals'],
        ];
    }

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

    private function payloadMatchesFilter($payload, ?array $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        if (!is_array($payload)) {
            return false;
        }

        $value = $this->getValueByPath($payload, $filter['path']);

        return $value === $filter['equals'];
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

    private function hashExport(array $payload): string
    {
        $clone = $payload;

        if (isset($clone['export_meta']['hash'])) {
            unset($clone['export_meta']['hash']);
        }

        return CanonicalJson::hash($clone);
    }
}
