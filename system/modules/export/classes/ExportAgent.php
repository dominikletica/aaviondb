<?php

declare(strict_types=1);

namespace AavionDB\Modules\Export;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Filters\FilterEngine;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Resolver\ResolverContext;
use AavionDB\Core\Resolver\ResolverEngine;
use AavionDB\Core\Storage\BrainRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_put_contents;
use function get_class;
use function implode;
use function in_array;
use function is_numeric;
use function is_scalar;
use function is_array;
use function is_bool;
use function is_dir;
use function is_string;
use function ltrim;
use function json_decode;
use function json_encode;
use function json_last_error;
use function ksort;
use function mkdir;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function uniqid;
use const DIRECTORY_SEPARATOR;
use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_EXTENSION;

final class ExportAgent
{
    private const DEFAULT_EXPORT_DESCRIPTION = 'Sliced export that contains data to use as context-source (SoT) for the current session.';

    private const DEFAULT_PRESET = 'context-unified';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_FORMATS = ['json', 'jsonl', 'markdown', 'text'];

    private const PLACEHOLDER_PATTERN = '/\$\{([a-z0-9_.:\\-]+)\}/i';

    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private FilterEngine $filters;

    private ResolverEngine $resolver;

    private PathLocator $paths;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->filters = new FilterEngine($this->brains, $this->logger);
        $this->resolver = new ResolverEngine($this->brains, $this->logger, $this->filters);
        $this->paths = $context->paths();
    }

    public function register(): void
    {
        $this->ensureConfigDefaults();
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

    private function ensureConfigDefaults(): void
    {
        try {
            if ($this->brains->getConfigValue('export.response', null, true) === null) {
                $this->brains->setConfigValue('export.response', true, true);
            }

            if ($this->brains->getConfigValue('export.save', null, true) === null) {
                $this->brains->setConfigValue('export.save', true, true);
            }

            if ($this->brains->getConfigValue('export.format', null, true) === null) {
                $this->brains->setConfigValue('export.format', 'json', true);
            }

            if ($this->brains->getConfigValue('export.nest_children', null, true) === null) {
                $this->brains->setConfigValue('export.nest_children', false, true);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to ensure export config defaults.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);
        }
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
        $destinationOverrides = $this->extractDestinationOverrides($parameters);

        return [
            'targets' => $normalizedTargets,
            'selectors' => $selectors,
            'description' => $description,
            'usage' => $usage,
            'preset' => $preset,
            'params' => $params,
            'destination_overrides' => $destinationOverrides,
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
        $selectors = $input['selectors'];
        $description = $input['description'];
        $usage = $input['usage'];

        $presetSlug = $input['preset'] ?? null;
        if ($presetSlug === null || $presetSlug === '') {
            $presetSlug = self::DEFAULT_PRESET;
        }

        $preset = $this->brains->getPreset($presetSlug);
        if ($preset === null) {
            throw new InvalidArgumentException(sprintf('Preset "%s" not found.', $presetSlug));
        }

        $settings = isset($preset['settings']) && is_array($preset['settings']) ? $preset['settings'] : [];
        $selection = isset($preset['selection']) && is_array($preset['selection']) ? $preset['selection'] : [];
        $templates = isset($preset['templates']) && is_array($preset['templates']) ? $templates = $preset['templates'] : [];

        if (!isset($templates['root']) || !isset($templates['entity'])) {
            throw new InvalidArgumentException(sprintf('Preset "%s" is missing required templates (root/entity).', $presetSlug));
        }

        $variables = $this->resolveVariables($settings['variables'] ?? [], $input['params']);
        $presetDestination = isset($settings['destination']) && is_array($settings['destination'])
            ? $settings['destination']
            : [];
        $baseDestination = $this->collectDestinationDefaults($presetDestination);
        $destination = $this->resolveDestination($baseDestination, $input['destination_overrides'] ?? []);
        $options = isset($settings['options']) && is_array($settings['options']) ? $settings['options'] : [];
        $missingPolicy = strtolower($options['missing_payload'] ?? 'empty');
        if (!in_array($missingPolicy, ['empty', 'skip'], true)) {
            $missingPolicy = 'empty';
        }

        $transform = $this->prepareTransform($settings['transform'] ?? []);
        $policies = $this->normalisePolicies($settings['policies'] ?? []);
        $selectionFilters = isset($selection['entities']) && is_array($selection['entities']) ? array_values($selection['entities']) : [];
        $payloadFilters = isset($selection['payload_filters']) && is_array($selection['payload_filters']) ? array_values($selection['payload_filters']) : [];
        $includeReferences = $this->normaliseIncludeReferences($selection['include_references'] ?? []);

        $mode = $selectors !== [] ? 'manual' : 'preset';

        $projects = $this->resolveProjectsForPreset(
            $input['targets'],
            $selection['projects'] ?? ['${project}'],
            $availableProjects,
            $variables
        );

        if ($projects === []) {
            throw new InvalidArgumentException('No projects resolved for export.');
        }

        if ($selectors !== [] && count($projects) > 1) {
            throw new InvalidArgumentException('Entity selectors are only supported when exporting a single project.');
        }

        $manualTargetMap = $selectors !== [] ? $this->groupSelectorsByEntity($selectors) : [];

        $projectSlices = [];
        $entities = [];
        $totalVersions = 0;

        foreach ($projects as $projectSlug) {
            $slice = $this->buildProjectSlice($projectSlug, [
                'mode' => $mode,
                'selection_filters' => $selectionFilters,
                'payload_filters' => $payloadFilters,
                'transform' => $transform,
                'params' => $variables,
                'manual_targets' => $manualTargetMap,
            ]);

            if ($slice['entities'] === []) {
                continue;
            }

            $projectSlices[$projectSlug] = $slice;
            $entities = array_merge($entities, $slice['entities']);
            $totalVersions += $slice['version_count'];
        }

        if ($projectSlices === []) {
            return [
                'payload' => [],
                'message' => 'No matching entities found for export.',
                'meta' => [
                    'preset' => $presetSlug,
                    'format' => $destination['format'],
                    'scope' => 'empty',
                    'projects' => $projects,
                    'entity_count' => 0,
                    'version_count' => 0,
                ],
            ];
        }

        $projectList = array_values(array_map(static fn (array $slice): array => $slice['project'], $projectSlices));

        $stats = $this->buildStats($projectList, $entities, $totalVersions);
        $index = $this->buildIndex($projectList, $entities);
        $scope = $this->determineScope($projects, $entities, $availableProjects);
        $action = $this->buildActionStringForContext($input, $projects, $presetSlug);
        $timestamp = $this->currentTimestamp();

        $metaInfo = $this->buildExportMeta($preset['meta'] ?? [], $presetSlug, $destination, $options, $description, $usage, $scope, $action, $timestamp);
        $guide = $this->buildGuideInfo($preset['meta'] ?? [], $usage);

        $context = [
            'meta' => $metaInfo,
            'guide' => $guide,
            'policies' => $policies,
            'index' => $index,
            'stats' => $stats,
            'scope' => $scope,
            'action' => $action,
            'preset' => $presetSlug,
            'description' => $description,
            'usage' => $usage,
            'generated_at' => $timestamp,
            'include_references' => $includeReferences,
        ];

        $templatePlaceholders = $this->collectTemplatePlaceholders($templates);
        $payloadPlaceholders = array_values(array_filter($templatePlaceholders, static fn (string $placeholder): bool => str_starts_with($placeholder, 'entity.payload.')));

        $rendered = $this->renderExportContent(
            $templates,
            $destination['format'],
            $context,
            $projectSlices,
            $variables,
            [
                'missing_payload' => $missingPolicy,
                'payload_placeholders' => $payloadPlaceholders,
                'nest_children' => $destination['nest_children'],
            ]
        );
        $content = $rendered['content'];
        $projectSummaries = $rendered['projects'];
        $entitySummaries = $rendered['entities'];
        $warnings = $rendered['warnings'];

        $savedPath = null;
        if ($destination['save']) {
            $savedPath = $this->persistExport($content, $destination, $presetSlug, $projectList, $timestamp);
        }

        $message = sprintf(
            'Export (%s) generated for %d project%s (%d entit%s, %d version%s).',
            strtoupper($destination['format']),
            count($projectList),
            count($projectList) === 1 ? '' : 's',
            $stats['entities'],
            $stats['entities'] === 1 ? 'y' : 'ies',
            $stats['versions'],
            $stats['versions'] === 1 ? '' : 's'
        );

        $payload = [
            'format' => $destination['format'],
            'meta' => $metaInfo,
            'stats' => $stats,
            'projects' => $projectSummaries,
            'entities' => $entitySummaries,
            'variables' => $variables,
            'path' => $savedPath,
        ];

        if ($destination['response']) {
            $payload['content'] = $content;
        }

        if (!empty($warnings)) {
            $payload['warnings'] = $warnings;
        }

        return [
            'payload' => $payload,
            'message' => $message,
            'meta' => [
                'preset' => $presetSlug,
                'format' => $destination['format'],
                'scope' => $scope,
                'projects' => array_map(static fn (array $project): string => $project['slug'], $projectList),
                'entity_count' => $stats['entities'],
                'version_count' => $stats['versions'],
                'path' => $savedPath,
                'warnings' => $warnings,
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
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array{path: ?string, response: bool, save: bool, format: string, nest_children: bool}
     */
    private function resolveDestination(array $base, array $overrides): array
    {
        $destination = [
            'path' => $this->normaliseDestinationPath($base['path'] ?? null),
            'response' => $this->normalizeBoolean($base['response'] ?? true, true),
            'save' => $this->normalizeBoolean($base['save'] ?? true, true),
            'format' => strtolower(trim((string) ($base['format'] ?? 'json'))),
            'nest_children' => $this->normalizeBoolean($base['nest_children'] ?? false, false),
        ];

        if (isset($overrides['path'])) {
            $destination['path'] = $this->normaliseDestinationPath($overrides['path']);
        }

        if (array_key_exists('response', $overrides)) {
            $destination['response'] = $this->normalizeBoolean($overrides['response'], $destination['response']);
        }

        if (array_key_exists('save', $overrides)) {
            $destination['save'] = $this->normalizeBoolean($overrides['save'], $destination['save']);
        }

        if (isset($overrides['format'])) {
            $destination['format'] = strtolower(trim((string) $overrides['format']));
        }

        if (array_key_exists('nest_children', $overrides)) {
            $destination['nest_children'] = $this->normalizeBoolean($overrides['nest_children'], $destination['nest_children']);
        }

        if (!in_array($destination['format'], self::SUPPORTED_FORMATS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported export format "%s".', $destination['format']));
        }

        return $destination;
    }

    /**
     * @param array<string, mixed> $presetDestination
     *
     * @return array<string, mixed>
     */
    private function collectDestinationDefaults(array $presetDestination): array
    {
        $defaults = [];

        $path = $this->brains->getConfigValue('export.path', null, true);
        if ($path !== null && $path !== '') {
            $defaults['path'] = $path;
        }

        $response = $this->brains->getConfigValue('export.response', null, true);
        if ($response !== null) {
            $defaults['response'] = $response;
        }

        $save = $this->brains->getConfigValue('export.save', null, true);
        if ($save !== null) {
            $defaults['save'] = $save;
        }

        $format = $this->brains->getConfigValue('export.format', null, true);
        if ($format !== null) {
            $defaults['format'] = $format;
        }

        $nestChildren = $this->brains->getConfigValue('export.nest_children', null, true);
        if ($nestChildren !== null) {
            $defaults['nest_children'] = $nestChildren;
        }

        return array_merge($defaults, $presetDestination);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed>                $input
     *
     * @return array<string, mixed>
     */
    private function resolveVariables(array $definitions, array $input): array
    {
        $resolved = [];
        $remaining = $input;

        foreach ($definitions as $name => $config) {
            if (!is_array($config)) {
                $config = [];
            }

            $type = isset($config['type']) ? (string) $config['type'] : 'text';
            $required = isset($config['required']) ? (bool) $config['required'] : false;
            $default = $config['default'] ?? null;

            $value = $remaining[$name] ?? $default;

            if ($value === null) {
                if ($required) {
                    throw new InvalidArgumentException(sprintf('Preset parameter "%s" is required.', $name));
                }

                unset($remaining[$name]);
                continue;
            }

            $resolved[$name] = $this->castVariable($value, $type);
            unset($remaining[$name]);
        }

        foreach ($remaining as $name => $value) {
            $resolved[$name] = $value;
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function castVariable($value, string $type)
    {
        $normalized = strtolower(trim($type));

        switch ($normalized) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'number':
            case 'float':
                return (float) $value;

            case 'bool':
            case 'boolean':
                return $this->normalizeBoolean($value, false);

            case 'comma_list':
            case 'csv':
                if (is_array($value)) {
                    return array_values(array_filter(array_map(static fn ($entry): string => trim((string) $entry), $value), static fn (string $entry): bool => $entry !== ''));
                }

                $split = preg_split('/\s*,\s*/', (string) $value) ?: [];

                return array_values(array_filter(array_map(static fn ($entry): string => trim((string) $entry), $split), static fn (string $entry): bool => $entry !== ''));

            case 'array':
                if (is_array($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }

                return (array) $value;

            case 'object':
                if (is_array($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }

                return [];

            case 'json':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                        return $decoded;
                    }
                }

                return $value;

            default:
                return $value;
        }
    }

    private function normalizeBoolean($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param mixed $path
     */
    private function normaliseDestinationPath($path): ?string
    {
        if ($path === null) {
            return null;
        }

        $resolved = trim((string) $path);

        return $resolved === '' ? null : $resolved;
    }

    /**
     * @param mixed $config
     *
     * @return array{enabled: bool, depth: int, modes: array<int, string>}
     */
    private function normaliseIncludeReferences($config): array
    {
        if (!is_array($config)) {
            $config = [];
        }

        $depth = isset($config['depth']) && is_numeric($config['depth'])
            ? max(0, (int) $config['depth'])
            : 0;

        $enabled = isset($config['enabled']) ? (bool) $config['enabled'] : ($depth > 0);

        $modes = [];
        if (isset($config['modes']) && is_array($config['modes'])) {
            $modes = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $config['modes']
            ), static fn (string $value): bool => $value !== ''));
        }

        return [
            'enabled' => $enabled,
            'depth' => $enabled ? $depth : 0,
            'modes' => $modes,
        ];
    }

    /**
     * @param array<string, mixed> $presetMeta
     * @param array{path: ?string, response: bool, save: bool, format: string, nest_children: bool} $destination
     *
     * @return array<string, mixed>
     */
    private function buildExportMeta(
        array $presetMeta,
        string $presetSlug,
        array $destination,
        array $options,
        string $description,
        string $usage,
        string $scope,
        string $action,
        string $timestamp
    ): array {
        $title = isset($presetMeta['title']) ? trim((string) $presetMeta['title']) : '';
        if ($title === '') {
            $title = sprintf('Export %s', $presetSlug);
        }

        $metaDescription = isset($presetMeta['description']) ? trim((string) $presetMeta['description']) : null;
        $presetUsage = isset($presetMeta['usage']) ? trim((string) $presetMeta['usage']) : null;

        return [
            'title' => $title,
            'description' => $description,
            'preset_description' => $metaDescription,
            'usage' => $usage,
            'preset_usage' => $presetUsage,
            'preset' => $presetSlug,
            'format' => $destination['format'],
            'generated_at' => $timestamp,
            'scope' => $scope,
            'action' => $action,
            'tags' => isset($presetMeta['tags']) && is_array($presetMeta['tags']) ? $presetMeta['tags'] : [],
            'destination' => [
                'path' => $destination['path'],
                'response' => $destination['response'],
                'save' => $destination['save'],
                'nest_children' => $destination['nest_children'],
            ],
            'flags' => [
                'read_only' => (bool) ($presetMeta['read_only'] ?? false),
                'immutable' => (bool) ($presetMeta['immutable'] ?? false),
            ],
            'missing_payload_policy' => $options['missing_payload'] ?? 'empty',
        ];
    }

    /**
     * @param array<string, mixed> $presetMeta
     *
     * @return array<string, mixed>
     */
    private function buildGuideInfo(array $presetMeta, string $usage): array
    {
        $notes = [];
        $metaDescription = isset($presetMeta['description']) ? trim((string) $presetMeta['description']) : '';
        if ($metaDescription !== '') {
            $notes[] = $metaDescription;
        }

        $presetUsage = isset($presetMeta['usage']) ? trim((string) $presetMeta['usage']) : '';

        return [
            'usage' => $presetUsage !== '' ? $presetUsage : $usage,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, string>                            $templates
     * @param array<string, mixed>                             $context
     * @param array<string, array<string, mixed>>              $projectSlices
     * @param array<string, mixed>                             $variables
     * @param array<string, mixed>                             $options
     *
     * @return array{content: string, projects: array<int, array<string, mixed>>, entities: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     */
    private function renderExportContent(
        array $templates,
        string $format,
        array $context,
        array $projectSlices,
        array $variables,
        array $options
    ): array {
        $format = strtolower($format);

        $missingPolicy = $options['missing_payload'] ?? 'empty';
        $payloadPlaceholders = $options['payload_placeholders'] ?? [];
        $nestChildren = (bool) ($options['nest_children'] ?? false);

        $globalReplacements = $this->buildGlobalReplacements($format, $context, $variables);

        $projectStrings = [];
        $projectSummaries = [];
        $entitySummaries = [];
        $entityStringsGlobal = [];
        $warnings = [];

        foreach ($projectSlices as $projectSlug => $slice) {
            $project = $slice['project'];
            $projectReplacements = $this->buildProjectReplacements($project, $format);

            $orderedEntities = $this->orderEntitiesForProject($slice['entities'], $project['slug'] ?? (string) $projectSlug, $nestChildren);

            $entityStrings = [];

            foreach ($orderedEntities as $ordered) {
                $entity = $ordered['entity'];
                $depth = $ordered['depth'];

                $result = $this->renderEntityTemplate(
                    $templates['entity'],
                    $format,
                    $globalReplacements,
                    $projectReplacements,
                    $entity,
                    $depth,
                    $payloadPlaceholders,
                    $missingPolicy,
                    $project['slug'] ?? (string) $projectSlug
                );

                $warnings = array_merge($warnings, $result['warnings']);

                if ($result['skipped']) {
                    continue;
                }

                $entityStrings[] = $result['content'];
                $entityStringsGlobal[] = $result['content'];
                $entitySummaries[] = [
                    'uid' => $entity['uid'],
                    'project' => $entity['project'],
                    'slug' => $entity['slug'],
                ];
            }

            if ($entityStrings === []) {
                continue;
            }

            $projectSummaries[] = $project;

            $entitiesAggregate = $this->aggregateStrings($entityStrings, $format);

            $projectTemplate = $templates['project'] ?? '';
            if ($projectTemplate !== '') {
                $projectStrings[] = $this->renderString(
                    $projectTemplate,
                    array_merge($globalReplacements, $projectReplacements, ['entities' => $entitiesAggregate])
                );
            } else {
                $projectStrings[] = $entitiesAggregate;
            }
        }

        $rootReplacements = array_merge(
            $globalReplacements,
            [
                'projects' => $this->aggregateStrings($projectStrings, $format),
                'entities' => $this->aggregateStrings($entityStringsGlobal, $format),
            ]
        );

        $contentRaw = $this->renderString($templates['root'], $rootReplacements);
        $content = $this->finaliseContent($contentRaw, $format);

        return [
            'content' => $content,
            'projects' => $projectSummaries,
            'entities' => $entitySummaries,
            'warnings' => $warnings,
        ];
    }

    private function finaliseContent(string $contentRaw, string $format): string
    {
        switch ($format) {
            case 'json':
                try {
                    $decoded = json_decode($contentRaw, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('Rendered JSON export is invalid: ' . $exception->getMessage(), 0, $exception);
                }

                try {
                    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?: '';
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('Failed to encode JSON export: ' . $exception->getMessage(), 0, $exception);
                }

            case 'jsonl':
                $normalised = implode("\n", array_filter(array_map(
                    static fn (string $line): string => trim($line),
                    preg_split('/\r?\n/', $contentRaw) ?: []
                ), static fn (string $line): bool => $line !== ''));

                return $normalised === '' ? '' : $normalised . "\n";

            case 'markdown':
            case 'text':
            default:
                return rtrim($contentRaw) . "\n";
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $variables
     *
     * @return array<string, string>
     */
    private function buildGlobalReplacements(string $format, array $context, array $variables): array
    {
        $map = [];

        if (isset($context['meta']) && is_array($context['meta'])) {
            $map += $this->buildObjectReplacements('meta', $context['meta'], $format);
        }

        if (isset($context['guide']) && is_array($context['guide'])) {
            $map += $this->buildObjectReplacements('guide', $context['guide'], $format);
        }

        if (isset($context['policies']) && is_array($context['policies'])) {
            $map += $this->buildObjectReplacements('policies', $context['policies'], $format);
        }

        if (isset($context['index']) && is_array($context['index'])) {
            $map += $this->buildObjectReplacements('index', $context['index'], $format);
        }

        if (isset($context['stats']) && is_array($context['stats'])) {
            $map += $this->buildObjectReplacements('stats', $context['stats'], $format);
        }

        $scalarKeys = ['preset', 'scope', 'action', 'description', 'usage', 'generated_at'];
        foreach ($scalarKeys as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }

            $map[$key] = $this->formatValue($context[$key], $format);
        }

        $map += $this->buildParamReplacements($variables, $format);

        return $map;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, string>
     */
    private function buildObjectReplacements(string $prefix, array $value, string $format): array
    {
        $map = [$prefix => $this->formatValue($value, $format, 'json')];

        foreach ($value as $key => $child) {
            $map += $this->buildValueReplacements($prefix . '.' . $key, $child, $format);
        }

        return $map;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>
     */
    private function buildValueReplacements(string $prefix, $value, string $format): array
    {
        if (is_array($value)) {
            $map = [$prefix => $this->formatValue($value, $format, 'json')];
            foreach ($value as $key => $child) {
                $map += $this->buildValueReplacements($prefix . '.' . $key, $child, $format);
            }

            return $map;
        }

        return [$prefix => $this->formatValue($value, $format)];
    }

    /**
     * @return array<string, string>
     */
    private function buildParamReplacements(array $variables, string $format): array
    {
        $map = [];

        foreach ($variables as $name => $value) {
            $map['param.' . $name] = $this->formatValue($value, $format, is_array($value) ? 'json' : 'default');
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function buildProjectReplacements(array $project, string $format): array
    {
        $map = [];

        foreach ($project as $key => $value) {
            $map['project.' . $key] = $this->formatValue($value, $format);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array<string, string>
     */
    private function buildEntityReplacements(array $entity, string $format, int $depth): array
    {
        $map = [];

        foreach ($entity as $key => $value) {
            if ($key === 'payload') {
                $map['entity.payload'] = $this->formatValue($value, $format, in_array($format, ['markdown', 'text'], true) ? 'pretty_json' : 'json');
                continue;
            }

            if ($key === 'payload_versions') {
                $map['entity.payload_versions'] = $this->formatValue($value, $format, 'json');
                continue;
            }

            if ($key === 'children' || $key === 'refs') {
                if (in_array($format, ['markdown', 'text'], true)) {
                    $map['entity.' . $key] = $value === [] ? '' : implode(', ', array_map(static fn ($entry): string => (string) $entry, $value));
                } else {
                    $map['entity.' . $key] = $this->formatValue($value, $format, 'json');
                }
                continue;
            }

            $map['entity.' . $key] = $this->formatValue($value, $format);
        }

        $displayName = $this->resolveEntityDisplayName($entity);
        $map['entity.display_name'] = $this->formatValue($displayName, $format);
        $map['entity.payload_pretty'] = $this->formatValue($entity['payload'] ?? null, $format, 'pretty_json');
        $map['entity.payload_inline'] = $this->formatValue($entity['payload'] ?? null, $format, 'json');
        $map['entity.payload_plain'] = $this->formatValue($entity['payload'] ?? null, $format, 'plain_payload');

        $map['entity.level'] = $this->formatValue($depth, $format);
        $map['entity.indent'] = $format === 'json' || $format === 'jsonl'
            ? ''
            : str_repeat('  ', max(0, $depth));
        $map['entity.heading_prefix'] = $this->computeHeadingPrefix($depth);

        if (isset($entity['payload']) && is_array($entity['payload'])) {
            $this->collectPayloadReplacements($entity['payload'], 'entity.payload', $map, $format);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function resolveEntityDisplayName(array $entity): string
    {
        $payload = $entity['payload'] ?? null;
        if (is_array($payload)) {
            foreach (['title', 'name', 'label'] as $candidate) {
                if (isset($payload[$candidate]) && is_string($payload[$candidate])) {
                    $value = trim($payload[$candidate]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return (string) ($entity['slug'] ?? $entity['uid'] ?? '');
    }

    /**
     * @param array<string|int, mixed> $payload
     * @param array<string, string>     $map
     */
    private function collectPayloadReplacements(array $payload, string $prefix, array &$map, string $format): void
    {
        foreach ($payload as $key => $value) {
            $path = $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $map[$path] = $this->formatValue($value, $format, in_array($format, ['markdown', 'text'], true) ? 'plain_payload' : 'json');
                $this->collectPayloadReplacements($value, $path, $map, $format);
            } else {
                $map[$path] = $this->formatValue($value, $format);
            }
        }
    }

    private function computeHeadingPrefix(int $depth): string
    {
        $base = '###';
        if ($depth <= 0) {
            return $base;
        }

        return $base . str_repeat('#', $depth);
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     *
     * @return array<int, array{entity: array<string, mixed>, depth: int}>
     */
    private function orderEntitiesForProject(array $entities, string $projectSlug, bool $nestChildren): array
    {
        if (!$nestChildren) {
            return array_map(static fn (array $entity): array => ['entity' => $entity, 'depth' => 0], $entities);
        }

        $entityMap = [];
        foreach ($entities as $entity) {
            $slug = $entity['slug'] ?? null;
            if ($slug === null) {
                continue;
            }
            $entityMap[$slug] = $entity;
        }

        $childrenMap = [];
        $hasParent = [];

        foreach ($entityMap as $slug => $entity) {
            if (!isset($entity['children']) || !is_array($entity['children'])) {
                continue;
            }

            foreach ($entity['children'] as $childReference) {
                if (!is_string($childReference) || $childReference === '') {
                    continue;
                }

                [$childProject, $childSlug] = $this->splitEntityReference($childReference);
                if ($childProject !== $projectSlug) {
                    continue;
                }

                if (!isset($entityMap[$childSlug])) {
                    continue;
                }

                $childrenMap[$slug][] = $childSlug;
                $hasParent[$childSlug] = true;
            }
        }

        $order = [];
        $visited = [];

        foreach ($entities as $entity) {
            $slug = $entity['slug'] ?? null;
            if ($slug === null || isset($visited[$slug])) {
                continue;
            }

            if (isset($hasParent[$slug])) {
                continue;
            }

            $this->collectEntityOrder($slug, $entityMap, $childrenMap, $order, $visited, 0);
        }

        foreach ($entityMap as $slug => $entity) {
            if (!isset($visited[$slug])) {
                $this->collectEntityOrder($slug, $entityMap, $childrenMap, $order, $visited, 0);
            }
        }

        return $order;
    }

    /**
     * @param array<string, array<string, mixed>> $entityMap
     * @param array<string, array<int, string>>   $childrenMap
     * @param array<int, array{entity: array<string, mixed>, depth: int}> $order
     * @param array<string, bool>                 $visited
     */
    private function collectEntityOrder(string $slug, array $entityMap, array $childrenMap, array &$order, array &$visited, int $depth): void
    {
        if (isset($visited[$slug])) {
            return;
        }

        $visited[$slug] = true;

        if (!isset($entityMap[$slug])) {
            return;
        }

        $order[] = ['entity' => $entityMap[$slug], 'depth' => $depth];

        if (!isset($childrenMap[$slug])) {
            return;
        }

        foreach ($childrenMap[$slug] as $childSlug) {
            $this->collectEntityOrder($childSlug, $entityMap, $childrenMap, $order, $visited, $depth + 1);
        }
    }

    private function splitEntityReference(string $reference): array
    {
        $parts = explode('.', $reference, 2);
        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, string> $projectReplacements
     * @param array<int, string>    $payloadPlaceholders
     *
     * @return array{content: string, warnings: array<int, array<string, string>>, skipped: bool}
     */
    private function renderEntityTemplate(
        string $template,
        string $format,
        array $globalReplacements,
        array $projectReplacements,
        array $entity,
        int $depth,
        array $payloadPlaceholders,
        string $missingPolicy,
        string $projectSlug
    ): array {
        $entityReplacements = $this->buildEntityReplacements($entity, $format, $depth);

        $missing = [];
        foreach ($payloadPlaceholders as $placeholder) {
            if (!array_key_exists($placeholder, $entityReplacements)) {
                $missing[] = $placeholder;
            }
        }

        $warnings = [];
        $entitySlug = (string) ($entity['slug'] ?? $entity['uid'] ?? '');

        if ($missing !== []) {
            if ($missingPolicy === 'skip') {
                foreach ($missing as $placeholder) {
                    $warnings[] = $this->buildMissingFieldWarning($projectSlug, $entitySlug, $placeholder, 'skip');
                }

                return [
                    'content' => '',
                    'warnings' => $warnings,
                    'skipped' => true,
                ];
            }

            foreach ($missing as $placeholder) {
                $entityReplacements[$placeholder] = $this->missingPlaceholderValue($format);
                $warnings[] = $this->buildMissingFieldWarning($projectSlug, $entitySlug, $placeholder, 'empty');
            }
        }

        $content = $this->renderString(
            $template,
            array_merge($globalReplacements, $projectReplacements, $entityReplacements)
        );

        return [
            'content' => $content,
            'warnings' => $warnings,
            'skipped' => false,
        ];
    }

    private function missingPlaceholderValue(string $format): string
    {
        return in_array($format, ['json', 'jsonl'], true) ? 'null' : '';
    }

    private function buildMissingFieldWarning(string $projectSlug, string $entitySlug, string $placeholder, string $policy): array
    {
        return [
            'type' => 'missing_payload_field',
            'project' => $projectSlug,
            'entity' => $entitySlug,
            'placeholder' => $placeholder,
            'policy' => $policy,
            'message' => sprintf('Placeholder "%s" missing in entity "%s" (%s); policy=%s.', $placeholder, $entitySlug, $projectSlug, $policy),
        ];
    }

    private function aggregateStrings(array $strings, string $format): string
    {
        if ($strings === []) {
            return $format === 'json' ? '[]' : '';
        }

        switch ($format) {
            case 'json':
                $trimmed = array_map(static fn (string $value): string => trim($value), $strings);

                return '[' . implode(',', $trimmed) . ']';

            case 'jsonl':
                return implode("\n", array_filter(array_map(static fn (string $value): string => trim($value), $strings), static fn (string $line): bool => $line !== ''));

            case 'markdown':
            case 'text':
            default:
                return implode("\n\n", array_map(static fn (string $value): string => rtrim($value), $strings));
        }
    }

    /**
     * @param array<string, string> $replacements
     */
    private function renderString(string $template, array $replacements): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($replacements): string {
                $key = $matches[1];

                return array_key_exists($key, $replacements)
                    ? (string) $replacements[$key]
                    : '';
            },
            $template
        ) ?? '';
    }

    /**
     * @param array<string, string> $templates
     *
     * @return array<int, string>
     */
    private function collectTemplatePlaceholders(array $templates): array
    {
        $placeholders = [];

        foreach ($templates as $template) {
            if (!is_string($template) || $template === '') {
                continue;
            }

            $count = preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);
            if ($count === false || $count === 0) {
                continue;
            }

            foreach ($matches[1] as $placeholder) {
                $placeholders[$placeholder] = true;
            }
        }

        return array_keys($placeholders);
    }

    /**
     * @param mixed $value
     */
    private function formatValue($value, string $format, string $mode = 'default'): string
    {
        if ($mode === 'plain_payload') {
            return $this->describePayloadPlain($value, $format);
        }

        if ($mode === 'json' || in_array($format, ['json', 'jsonl'], true)) {
            if ($mode === 'pretty_json') {
                return $this->encodePrettyJson($value);
            }

            return $this->encodeJson($value);
        }

        if ($mode === 'pretty_json') {
            return $this->encodePrettyJson($value);
        }

        if ($mode === 'json') {
            return $this->encodeJson($value);
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if (in_array($format, ['markdown', 'text'], true)) {
                return $this->describePayloadPlain($value, $format);
            }

            return $this->encodePrettyJson($value);
        }

        return $this->encodePrettyJson($value);
    }

    private function describePayloadPlain($value, string $format, int $depth = 0): string
    {
        if (in_array($format, ['json', 'jsonl'], true)) {
            return $this->encodePrettyJson($value);
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (!is_array($value)) {
            return $this->encodePrettyJson($value);
        }

        if ($value === []) {
            return '';
        }

        $indent = str_repeat('  ', $depth);
        $lines = [];

        if ($this->isListArray($value)) {
            foreach ($value as $item) {
                $entry = $this->describePayloadPlain($item, $format, $depth + 1);
                $lines[] = $indent . '- ' . ($entry === '' ? '(empty)' : $entry);
            }

            return implode("\n", $lines);
        }

        foreach ($value as $key => $item) {
            $label = (string) $key;
            if (is_array($item)) {
                $child = $this->describePayloadPlain($item, $format, $depth + 1);
                $lines[] = $indent . '- ' . $label . ':' . ($child === '' ? '' : "\n" . $child);
            } else {
                $scalar = $this->describePayloadPlain($item, $format, $depth + 1);
                $lines[] = $indent . '- ' . $label . ': ' . ($scalar === '' ? '(empty)' : $scalar);
            }
        }

        return implode("\n", $lines);
    }

    private function isListArray(array $value): bool
    {
        return array_values($value) === $value;
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Failed to encode JSON value: ' . $exception->getMessage(), 0, $exception);
        }

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param mixed $value
     */
    private function encodePrettyJson($value): string
    {
        try {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Failed to encode JSON value: ' . $exception->getMessage(), 0, $exception);
        }

        return $encoded === false ? '' : $encoded;
    }

    /**
     * @param array<int, array<string, mixed>> $projectList
     */
    private function persistExport(string $content, array $destination, string $presetSlug, array $projectList, string $timestamp): string
    {
        $format = $destination['format'];
        $extension = $this->determineExtension($format);

        if ($destination['path'] === null) {
            $directory = $this->paths->userExports();
            $this->ensureDirectory($directory);
            $filename = $this->buildExportFilename($presetSlug, $projectList, $timestamp, $extension);
            $targetPath = $directory . DIRECTORY_SEPARATOR . $filename;
        } else {
            $resolved = $this->resolveOutputPath($destination['path']);
            if ($this->looksLikeDirectory($resolved)) {
                $this->ensureDirectory($resolved);
                $filename = $this->buildExportFilename($presetSlug, $projectList, $timestamp, $extension);
                $targetPath = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            } else {
                $this->ensureDirectory(dirname($resolved));
                $targetPath = $resolved;
                $currentExtension = strtolower((string) pathinfo($targetPath, PATHINFO_EXTENSION));
                if ($currentExtension === '') {
                    $targetPath .= '.' . $extension;
                }
            }
        }

        if (@file_put_contents($targetPath, $content) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write export file "%s".', $targetPath));
        }

        return $targetPath;
    }

    /**
     * @param array<int, array<string, mixed>> $projectList
     */
    private function buildExportFilename(string $presetSlug, array $projectList, string $timestamp, string $extension): string
    {
        $projectSlug = 'multi';
        if (count($projectList) === 1) {
            $projectSlug = $this->sanitiseFilenameSegment((string) ($projectList[0]['slug'] ?? 'project'));
        }

        $presetSegment = $this->sanitiseFilenameSegment($presetSlug);
        $timestampSegment = str_replace([':', ' '], '-', $timestamp);

        return sprintf('%s-%s-%s.%s', $projectSlug, $presetSegment, $timestampSegment, $extension);
    }

    private function resolveOutputPath(string $path): string
    {
        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if ($normalised === '') {
            return $this->paths->userExports();
        }

        if ($this->isAbsolutePath($normalised)) {
            return $normalised;
        }

        return $this->paths->root() . DIRECTORY_SEPARATOR . ltrim($normalised, DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new InvalidArgumentException(sprintf('Unable to create directory "%s".', $path));
        }
    }

    private function looksLikeDirectory(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (substr($path, -1) === DIRECTORY_SEPARATOR) {
            return true;
        }

        return !str_contains($path, '.');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function determineExtension(string $format): string
    {
        return match ($format) {
            'jsonl' => 'jsonl',
            'markdown' => 'md',
            'text' => 'txt',
            default => 'json',
        };
    }

    private function sanitiseFilenameSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-_.]/', '-', $value) ?? $value;

        return trim($value, '-_.') ?: 'export';
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
        array $projectSelectors,
        array $availableProjects,
        array $params
    ): array {
        $selectors = $projectSelectors;

        if (!is_array($selectors) || $selectors === []) {
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
     * @return array<string, mixed>
     */
    private function extractDestinationOverrides(array $parameters): array
    {
        $overrides = [];

        if (isset($parameters['path'])) {
            $overrides['path'] = $parameters['path'];
        }

        if (isset($parameters['format'])) {
            $overrides['format'] = $parameters['format'];
        }

        if (array_key_exists('response', $parameters)) {
            $overrides['response'] = $parameters['response'];
        }

        if (array_key_exists('save', $parameters)) {
            $overrides['save'] = $parameters['save'];
        }

        if (array_key_exists('nest_children', $parameters)) {
            $overrides['nest_children'] = $parameters['nest_children'];
        } elseif (array_key_exists('nest-children', $parameters)) {
            $overrides['nest_children'] = $parameters['nest-children'];
        }

        return $overrides;
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
