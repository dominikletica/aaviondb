<?php

declare(strict_types=1);

namespace AavionDB\Modules\Preset;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Storage\BrainRepository;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_map;
use function array_shift;
use function array_unshift;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function str_replace;
use function strtolower;
use function trim;
use function substr;
use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PresetAgent
{
    private const DEFAULT_PRESET = 'default';

    private const DEFAULT_LAYOUT = 'context-unified-v2';

    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private PathLocator $paths;

    private PresetValidator $validator;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->paths = $context->paths();
        $this->validator = new PresetValidator();
    }

    public function register(): void
    {
        $this->ensureDefaults();
        $this->registerParser();
        $this->registerCommands();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('preset', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('preset list');
                return;
            }

            $sub = strtolower(trim((string) array_shift($tokens)));

            switch ($sub) {
                case 'list':
                    $context->setAction('preset list');
                    break;
                case 'show':
                    $context->setAction('preset show');
                    break;
                case 'create':
                    $context->setAction('preset create');
                    break;
                case 'update':
                    $context->setAction('preset update');
                    break;
                case 'delete':
                case 'remove':
                    $context->setAction('preset delete');
                    break;
                case 'copy':
                    $context->setAction('preset copy');
                    break;
                case 'import':
                    $context->setAction('preset import');
                    break;
                case 'export':
                    $context->setAction('preset export');
                    break;
                case 'vars':
                case 'parameters':
                    $context->setAction('preset vars');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('preset list');
                    break;
            }

            $this->injectParameters($context, $tokens, $context->action());
        }, 10);
    }

    private function registerCommands(): void
    {
        $this->context->commands()->register('preset list', fn (array $parameters): CommandResponse => $this->presetListCommand());
        $this->context->commands()->register('preset show', fn (array $parameters): CommandResponse => $this->presetShowCommand($parameters));
        $this->context->commands()->register('preset create', fn (array $parameters): CommandResponse => $this->presetCreateCommand($parameters));
        $this->context->commands()->register('preset update', fn (array $parameters): CommandResponse => $this->presetUpdateCommand($parameters));
        $this->context->commands()->register('preset delete', fn (array $parameters): CommandResponse => $this->presetDeleteCommand($parameters));
        $this->context->commands()->register('preset copy', fn (array $parameters): CommandResponse => $this->presetCopyCommand($parameters));
        $this->context->commands()->register('preset import', fn (array $parameters): CommandResponse => $this->presetImportCommand($parameters));
        $this->context->commands()->register('preset export', fn (array $parameters): CommandResponse => $this->presetExportCommand($parameters));
        $this->context->commands()->register('preset vars', fn (array $parameters): CommandResponse => $this->presetVarsCommand($parameters));
    }

    private function injectParameters(ParserContext $context, array $tokens, string $action): void
    {
        $parameters = [];

        switch ($action) {
            case 'preset show':
            case 'preset delete':
            case 'preset create':
            case 'preset update':
            case 'preset export':
            case 'preset vars':
                if ($tokens !== []) {
                    $parameters['slug'] = array_shift($tokens);
                }
                break;
            case 'preset copy':
                if ($tokens !== []) {
                    $parameters['source'] = array_shift($tokens);
                }
                if ($tokens !== []) {
                    $parameters['target'] = array_shift($tokens);
                }
                break;
            case 'preset import':
                if ($tokens !== []) {
                    $parameters['slug'] = array_shift($tokens);
                }
                if ($tokens !== []) {
                    $parameters['path'] = array_shift($tokens);
                }
                break;
        }

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            $key = $token;
            $value = true;

            if (str_contains($token, '=')) {
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

    private function presetListCommand(): CommandResponse
    {
        $items = $this->brains->listPresets();

        return CommandResponse::success('preset list', [
            'count' => count($items),
            'items' => $items,
        ], sprintf('%d preset%s available.', count($items), count($items) === 1 ? '' : 's'));
    }

    private function presetShowCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset show', 'Preset slug is required.');
        }

        $preset = $this->brains->getPreset($slug);
        if ($preset === null) {
            return CommandResponse::error('preset show', sprintf('Preset "%s" not found.', $slug));
        }

        return CommandResponse::success('preset show', [
            'preset' => $preset,
        ], sprintf('Preset "%s" loaded.', $slug));
    }

    private function presetCreateCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset create', 'Preset slug is required.');
        }

        if ($slug === self::DEFAULT_PRESET) {
            return CommandResponse::error('preset create', 'The default preset is managed by the system and cannot be recreated.');
        }

        if ($this->brains->presetExists($slug)) {
            return CommandResponse::error('preset create', sprintf('Preset "%s" already exists.', $slug));
        }

        try {
            $definition = $this->resolveDefinition($parameters);
            $definition = $this->validator->validate($definition);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('preset create', $exception->getMessage());
        } catch (JsonException $exception) {
            return CommandResponse::error('preset create', $exception->getMessage());
        }

        $definition['meta']['read_only'] = $definition['meta']['read_only'] ?? false;

        try {
            $this->brains->savePreset($slug, $definition, false);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to create preset.', [
                'slug' => $slug,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            return CommandResponse::error('preset create', $exception->getMessage());
        }

        return CommandResponse::success('preset create', [
            'preset' => $this->brains->getPreset($slug),
        ], sprintf('Preset "%s" created.', $slug));
    }

    private function presetUpdateCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset update', 'Preset slug is required.');
        }

        $current = $this->brains->getPreset($slug);
        if ($current === null) {
            return CommandResponse::error('preset update', sprintf('Preset "%s" not found.', $slug));
        }

        if ($this->isPresetProtected($current)) {
            return CommandResponse::error('preset update', sprintf('Preset "%s" is read-only.', $slug));
        }

        try {
            $definition = $this->resolveDefinition($parameters);
            $definition = $this->validator->validate($definition);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('preset update', $exception->getMessage());
        } catch (JsonException $exception) {
            return CommandResponse::error('preset update', $exception->getMessage());
        }

        if (isset($current['meta']) && is_array($current['meta'])) {
            foreach (['read_only', 'immutable'] as $flag) {
                if (isset($current['meta'][$flag]) && !isset($definition['meta'][$flag])) {
                    $definition['meta'][$flag] = (bool) $current['meta'][$flag];
                }
            }
        }

        try {
            $this->brains->savePreset($slug, $definition, true);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to update preset.', [
                'slug' => $slug,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            return CommandResponse::error('preset update', $exception->getMessage());
        }

        return CommandResponse::success('preset update', [
            'preset' => $this->brains->getPreset($slug),
        ], sprintf('Preset "%s" updated.', $slug));
    }

    private function presetDeleteCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset delete', 'Preset slug is required.');
        }

        $current = $this->brains->getPreset($slug);
        if ($current === null) {
            return CommandResponse::error('preset delete', sprintf('Preset "%s" not found.', $slug));
        }

        if ($this->isPresetProtected($current)) {
            return CommandResponse::error('preset delete', sprintf('Preset "%s" is read-only.', $slug));
        }

        try {
            $this->brains->deletePreset($slug);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete preset.', [
                'slug' => $slug,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            return CommandResponse::error('preset delete', $exception->getMessage());
        }

        return CommandResponse::success('preset delete', [
            'slug' => $slug,
        ], sprintf('Preset "%s" deleted.', $slug));
    }

    private function presetCopyCommand(array $parameters): CommandResponse
    {
        $sourceSlug = $this->normaliseSlug($parameters['source'] ?? '');
        $targetSlug = $this->normaliseSlug($parameters['target'] ?? '');

        if ($sourceSlug === '' || $targetSlug === '') {
            return CommandResponse::error('preset copy', 'Source and target slugs are required.');
        }

        $source = $this->brains->getPreset($sourceSlug);
        if ($source === null) {
            return CommandResponse::error('preset copy', sprintf('Preset "%s" not found.', $sourceSlug));
        }

        $force = $this->isTruthy($parameters['force'] ?? false);

        if (!$force && $this->brains->presetExists($targetSlug)) {
            return CommandResponse::error('preset copy', sprintf('Preset "%s" already exists.', $targetSlug));
        }

        unset($source['meta']['slug'], $source['meta']['created_at'], $source['meta']['updated_at']);
        $source['meta']['read_only'] = false;
        $source['meta']['immutable'] = false;

        try {
            $definition = $this->validator->validate($source);
            $this->brains->savePreset($targetSlug, $definition, true);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('preset copy', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to copy preset.', [
                'source' => $sourceSlug,
                'target' => $targetSlug,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            return CommandResponse::error('preset copy', $exception->getMessage());
        }

        return CommandResponse::success('preset copy', [
            'preset' => $this->brains->getPreset($targetSlug),
        ], sprintf('Preset "%s" copied to "%s".', $sourceSlug, $targetSlug));
    }

    private function presetImportCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset import', 'Preset slug is required.');
        }

        $path = isset($parameters['path']) ? (string) $parameters['path'] : null;
        $file = isset($parameters['file']) ? (string) $parameters['file'] : null;
        $sourcePath = $this->resolveImportPath($path ?? $file, $slug);

        if (!file_exists($sourcePath)) {
            return CommandResponse::error('preset import', sprintf('Preset file "%s" not found.', $sourcePath));
        }

        $force = $this->isTruthy($parameters['force'] ?? false);
        $allowUpdate = $force || $this->isTruthy($parameters['update'] ?? false);

        if (!$allowUpdate && $this->brains->presetExists($slug)) {
            return CommandResponse::error('preset import', sprintf('Preset "%s" already exists. Use --force to overwrite.', $slug));
        }

        try {
            $definition = $this->readDefinitionFromFile($sourcePath);
            $definition = $this->validator->validate($definition);
            $this->brains->savePreset($slug, $definition, true);
        } catch (InvalidArgumentException|JsonException $exception) {
            return CommandResponse::error('preset import', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to import preset.', [
                'slug' => $slug,
                'path' => $sourcePath,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);

            return CommandResponse::error('preset import', $exception->getMessage());
        }

        return CommandResponse::success('preset import', [
            'preset' => $this->brains->getPreset($slug),
            'path' => $sourcePath,
        ], sprintf('Preset "%s" imported from %s.', $slug, $sourcePath));
    }

    private function presetExportCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset export', 'Preset slug is required.');
        }

        $preset = $this->brains->getPreset($slug);
        if ($preset === null) {
            return CommandResponse::error('preset export', sprintf('Preset "%s" not found.', $slug));
        }

        $path = isset($parameters['path']) ? (string) $parameters['path'] : null;
        $file = isset($parameters['file']) ? (string) $parameters['file'] : null;
        $targetPath = $this->resolveExportPath($path ?? $file, $slug);

        $directory = dirname($targetPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return CommandResponse::error('preset export', sprintf('Unable to create directory "%s".', $directory));
        }

        $payload = json_encode($preset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return CommandResponse::error('preset export', 'Failed to serialise preset.');
        }

        if (@file_put_contents($targetPath, $payload) === false) {
            return CommandResponse::error('preset export', sprintf('Unable to write preset file "%s".', $targetPath));
        }

        return CommandResponse::success('preset export', [
            'path' => $targetPath,
        ], sprintf('Preset "%s" exported to %s.', $slug, $targetPath));
    }

    private function presetVarsCommand(array $parameters): CommandResponse
    {
        $slug = $this->normaliseSlug($parameters['slug'] ?? '');
        if ($slug === '') {
            return CommandResponse::error('preset vars', 'Preset slug is required.');
        }

        $preset = $this->brains->getPreset($slug);
        if ($preset === null) {
            return CommandResponse::error('preset vars', sprintf('Preset "%s" not found.', $slug));
        }

        $meta = isset($preset['meta']) && is_array($preset['meta']) ? $preset['meta'] : [];

        $placeholders = isset($preset['placeholders']) && is_array($preset['placeholders'])
            ? array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $preset['placeholders']
            ), static fn (string $value): bool => $value !== ''))
            : [];

        $placeholders[] = 'project';

        $placeholderSet = [];
        foreach ($placeholders as $placeholder) {
            $placeholderSet[$placeholder] = true;
        }

        foreach (array_keys($params) as $key) {
            $placeholderSet['param.' . $key] = true;
        }

        $placeholders = array_values(array_unique(array_keys($placeholderSet)));

        $params = [];
        if (isset($preset['params']) && is_array($preset['params'])) {
            foreach ($preset['params'] as $name => $config) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                if (!is_array($config)) {
                    $config = ['default' => $config];
                }

                $params[$name] = [
                    'required' => isset($config['required']) ? (bool) $config['required'] : false,
                    'default' => $config['default'] ?? null,
                    'description' => isset($config['description']) ? (string) $config['description'] : null,
                    'type' => isset($config['type']) ? (string) $config['type'] : 'text',
                ];
            }
        }

        $placeholderDetails = [];
        foreach ($placeholders as $placeholder) {
            $detail = ['placeholder' => $placeholder, 'type' => 'text'];

            if ($placeholder === 'project') {
                $detail['source'] = 'project';
            } elseif (str_starts_with($placeholder, 'param.')) {
                $name = substr($placeholder, 6);
                $detail['source'] = 'param';
                $detail['name'] = $name;
                $detail['type'] = $params[$name]['type'] ?? 'text';
                $detail['required'] = $params[$name]['required'] ?? false;
                if (isset($params[$name]['default'])) {
                    $detail['default'] = $params[$name]['default'];
                }
            } else {
                $detail['source'] = 'custom';
            }

            $placeholderDetails[] = $detail;
        }

        $notes = [
            'project' => 'Resolved from the project target supplied to `export` (e.g. `export myproject`).',
            'param.*' => 'Pass values via `--param.<name>=value` (alias `--var.<name>=value`) or JSON payloads under `params`.',
            'types' => 'Parameter types may be: text, int, number, float, bool, array, object, comma_list (CSV).',
        ];

        return CommandResponse::success('preset vars', [
            'slug' => $slug,
            'layout' => $meta['layout'] ?? self::DEFAULT_LAYOUT,
            'placeholders' => $placeholders,
            'placeholder_details' => $placeholderDetails,
            'params' => $params,
            'notes' => $notes,
        ], sprintf('Preset "%s" exposes %d placeholder(s).', $slug, count($placeholders)));
    }

    private function ensureDefaults(): void
    {
        try {
            if (!$this->brains->presetExists(self::DEFAULT_PRESET)) {
                $definition = $this->validator->validate($this->defaultPresetDefinition());
                $definition['meta']['read_only'] = true;
                $definition['meta']['immutable'] = true;
                $this->brains->savePreset(self::DEFAULT_PRESET, $definition, false);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Failed to ensure default preset.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);
        }

        try {
            if ($this->brains->getExportLayout(self::DEFAULT_LAYOUT) === null) {
                $this->brains->saveExportLayout(self::DEFAULT_LAYOUT, $this->defaultLayoutDefinition(), false);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Failed to ensure default export layout.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ]);
        }
    }

    private function isPresetProtected(array $preset): bool
    {
        $meta = $preset['meta'] ?? [];
        if (!is_array($meta)) {
            return false;
        }

        if (($meta['immutable'] ?? false) === true) {
            return true;
        }

        return ($meta['read_only'] ?? false) === true;
    }

    /**
     * @throws InvalidArgumentException
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function resolveDefinition(array $parameters): array
    {
        if (array_key_exists('payload', $parameters)) {
            $payload = $this->decodeDefinition($parameters['payload']);
            if ($payload !== null) {
                return $payload;
            }
        }

        if (array_key_exists('value', $parameters)) {
            $payload = $this->decodeDefinition($parameters['value']);
            if ($payload !== null) {
                return $payload;
            }
        }

        $file = isset($parameters['file']) ? (string) $parameters['file'] : null;
        if ($file !== null) {
            return $this->readDefinitionFromFile($this->resolvePath($file));
        }

        throw new InvalidArgumentException('Preset definition payload or --file path is required.');
    }

    /**
     * @param mixed $value
     *
     * @throws JsonException
     *
     * @return array<string, mixed>|null
     */
    private function decodeDefinition($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Preset payload must decode to an object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readDefinitionFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(sprintf('Preset file "%s" not found.', $path));
        }

        $contents = (string) file_get_contents($path);

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Preset file "%s" contains invalid JSON: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Preset file "%s" must decode to an object.', $path));
        }

        return $decoded;
    }

    private function resolveImportPath(?string $path, string $slug): string
    {
        if ($path !== null && trim($path) !== '') {
            return $this->resolvePath($path);
        }

        return $this->defaultPresetPath($slug);
    }

    private function resolveExportPath(?string $path, string $slug): string
    {
        if ($path !== null && trim($path) !== '') {
            return $this->resolvePath($path);
        }

        return $this->defaultPresetPath($slug);
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->paths->root();
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return $this->paths->root() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function defaultPresetPath(string $slug): string
    {
        return $this->presetDirectory() . DIRECTORY_SEPARATOR . $slug . '.json';
    }

    private function presetDirectory(): string
    {
        $directory = $this->paths->user() . DIRECTORY_SEPARATOR . 'presets' . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->logger->warning('Unable to create preset directory.', ['path' => $directory]);
        }

        return $directory;
    }

    private function defaultPresetDefinition(): array
    {
        return [
            'meta' => [
                'description' => 'Default context export preset.',
                'usage' => 'Exports the current project with canonical entities for LLM ingestion.',
                'layout' => self::DEFAULT_LAYOUT,
                'read_only' => true,
                'immutable' => true,
            ],
            'selection' => [
                'projects' => ['${project}'],
                'entities' => [],
                'payload_filters' => [],
            ],
            'transform' => [
                'whitelist' => [],
                'blacklist' => [],
                'post' => [],
            ],
            'policies' => [
                'references' => [
                    'include' => true,
                    'depth' => 1,
                ],
                'cache' => [
                    'ttl' => 3600,
                    'invalidate_on' => ['hash', 'commit'],
                ],
            ],
            'placeholders' => ['project'],
            'params' => [],
        ];
    }

    private function defaultLayoutDefinition(): array
    {
        return [
            'format' => 'json',
            'meta' => [
                'description' => 'Unified JSON export optimised for deterministic LLM context ingestion.',
            ],
            'template' => [
                'meta' => [
                    'layout' => self::DEFAULT_LAYOUT,
                    'preset' => '${preset}',
                    'generated_at' => '${generated_at}',
                    'scope' => '${scope}',
                    'description' => '${description}',
                    'action' => '${action}',
                ],
                'guide' => [
                    'usage' => '${usage}',
                    'notes' => [
                        'Each entity is self-contained and references others via "@project.slug" or "@project.slug.field".',
                        'Use the "refs" array to explore relationships without navigating nested trees.',
                        'Active versions represent canon; include inactive versions intentionally for historical slices.',
                        'Field references such as "@project.slug.field.path" resolve nested payload values deterministically.',
                    ],
                ],
                'policies' => [
                    'load' => 'Treat "active_version" as canonical unless selectors override.',
                    'cache' => [
                        'ttl' => '${policies.cache.ttl}',
                        'invalidate_on' => '${policies.cache.invalidate_on}',
                    ],
                    'references' => [
                        'include' => '${policies.references.include}',
                        'depth' => '${policies.references.depth}',
                    ],
                ],
                'index' => '${index}',
                'entities' => '${entities}',
                'stats' => '${stats}',
            ],
            'entity_template' => [
                'uid' => '${entity.uid}',
                'project' => '${entity.project}',
                'slug' => '${entity.slug}',
                'version' => '${entity.version}',
                'commit' => '${entity.commit}',
                'active' => '${entity.active}',
                'parent' => '${entity.parent}',
                'children' => '${entity.children}',
                'refs' => '${entity.refs}',
                'payload' => '${entity.payload}',
                'payload_versions' => '${entity.payload_versions}',
            ],
        ];
    }

    private function normaliseSlug($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));
        $value = (string) \preg_replace('/[^a-z0-9\-_.]/', '-', $value);

        return trim($value, '-_.');
    }

    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
