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
use function array_keys;
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
use function preg_match_all;
use function sort;
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
    private const DEFAULT_PRESET = 'context-unified';

    private const PLACEHOLDER_PATTERN = '/\$\{([a-z0-9_.:\\-]+)\}/i';

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
            $cloneSlug = $this->generateCloneSlug($slug);

            try {
                $definition = $this->resolveDefinition($parameters);
                $definition = $this->validator->validate($definition);
            } catch (InvalidArgumentException $exception) {
                return CommandResponse::error('preset update', $exception->getMessage());
            } catch (JsonException $exception) {
                return CommandResponse::error('preset update', $exception->getMessage());
            }

            try {
                $this->brains->savePreset($cloneSlug, $definition, false);
            } catch (Throwable $exception) {
                $this->logger->error('Failed to create clone preset while updating read-only preset.', [
                    'source' => $slug,
                    'clone' => $cloneSlug,
                    'exception' => [
                        'message' => $exception->getMessage(),
                        'class' => $exception::class,
                    ],
                ]);

                return CommandResponse::error('preset update', $exception->getMessage());
            }

            return CommandResponse::success('preset update', [
                'preset' => $this->brains->getPreset($cloneSlug),
                'clone' => $cloneSlug,
                'note' => sprintf('Preset "%s" is read-only. Changes were saved as "%s" instead.', $slug, $cloneSlug),
            ], sprintf('Preset "%s" is read-only. Created clone "%s" instead.', $slug, $cloneSlug));
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

    private function generateCloneSlug(string $baseSlug): string
    {
        $suffix = 2;
        $candidate = $baseSlug . '-v' . $suffix;

        while ($this->brains->presetExists($candidate)) {
            $suffix++;
            $candidate = $baseSlug . '-v' . $suffix;
        }

        return $candidate;
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
        $options = isset($preset['settings']['options']) && is_array($preset['settings']['options'])
            ? $preset['settings']['options']
            : ['missing_payload' => 'empty'];
        $settings = isset($preset['settings']) && is_array($preset['settings']) ? $preset['settings'] : [];
        $variables = isset($settings['variables']) && is_array($settings['variables']) ? $settings['variables'] : [];
        $templates = isset($preset['templates']) && is_array($preset['templates']) ? $preset['templates'] : [];

        $placeholderSet = [];
        foreach ($this->collectTemplatePlaceholders($templates) as $placeholder) {
            $placeholderSet[$placeholder] = true;
        }

        foreach (array_keys($variables) as $name) {
            $placeholderSet['param.' . $name] = true;
        }

        $placeholders = array_keys($placeholderSet);
        sort($placeholders, SORT_STRING);

        $placeholderDetails = [];
        foreach ($placeholders as $placeholder) {
            $detail = ['placeholder' => $placeholder, 'source' => 'template'];

            if (str_starts_with($placeholder, 'param.')) {
                $name = substr($placeholder, 6);
                $config = $variables[$name] ?? [];
                $detail['source'] = 'param';
                $detail['name'] = $name;
                $detail['type'] = $config['type'] ?? 'text';
                $detail['required'] = $config['required'] ?? false;
                if (array_key_exists('default', $config)) {
                    $detail['default'] = $config['default'];
                }
                if (($config['description'] ?? null) !== null) {
                    $detail['description'] = $config['description'];
                }
            } elseif (str_starts_with($placeholder, 'project.')) {
                $detail['source'] = 'project';
            } elseif (str_starts_with($placeholder, 'entity.')) {
                $detail['source'] = 'entity';
            } elseif (str_starts_with($placeholder, 'meta.')) {
                $detail['source'] = 'meta';
            } else {
                $detail['source'] = 'context';
            }

            $placeholderDetails[] = $detail;
        }

        $notes = [
            'param.*' => 'Pass variables via `--param.<name>=value` (alias `--var.<name>=value`) or provide a JSON payload with a `params` object.',
            'project' => 'Resolved from the project slug supplied to `export` (e.g. `export myproject`).',
            'format' => 'Templates render differently depending on the destination format (json, jsonl, markdown, text).',
        ];

        return CommandResponse::success('preset vars', [
            'slug' => $slug,
            'format' => $settings['destination']['format'] ?? null,
            'variables' => $variables,
            'placeholders' => $placeholders,
            'placeholder_details' => $placeholderDetails,
            'meta' => $meta,
            'options' => $options,
            'notes' => $notes,
        ], sprintf('Preset "%s" exposes %d placeholder(s).', $slug, count($placeholders)));
    }

    private function ensureDefaults(): void
    {
        foreach ($this->defaultPresetDefinitions() as $slug => $definition) {
            try {
                if ($this->brains->presetExists($slug)) {
                    continue;
                }

                $validated = $this->validator->validate($definition);
                $meta = $validated['meta'] ?? [];
                $meta['read_only'] = true;
                $meta['immutable'] = true;
                $validated['meta'] = $meta;
                $this->brains->savePreset($slug, $validated, false);
            } catch (Throwable $exception) {
                $this->logger->error('Failed to seed default preset.', [
                    'preset' => $slug,
                    'exception' => [
                        'message' => $exception->getMessage(),
                        'class' => $exception::class,
                    ],
                ]);
            }
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultPresetDefinitions(): array
    {
        return [
            self::DEFAULT_PRESET => $this->buildContextUnifiedPreset(),
            'context-jsonl' => $this->buildContextJsonlPreset(),
            'context-markdown-unified' => $this->buildContextMarkdownUnifiedPreset(),
            'context-markdown-slim' => $this->buildContextMarkdownSlimPreset(),
            'context-markdown-plain' => $this->buildContextMarkdownPlainPreset(),
            'context-text-plain' => $this->buildContextTextPlainPreset(),
        ];
    }

    private function buildContextUnifiedPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context Unified',
                'Deterministic JSON bundle for rich LLM ingestion.',
                'Use when you need a full project slice with metadata, index, and payloads in a single JSON object.',
                ['default', 'json']
            ),
            'settings' => $this->baseSettings('json'),
            'selection' => $this->baseSelection(true, 1),
            'templates' => [
                'root' => '{
  "meta": ${meta},
  "guide": ${guide},
  "policies": ${policies},
  "index": ${index},
  "projects": ${projects},
  "stats": ${stats}
}',
                'project' => '{
  "slug": ${project.slug},
  "title": ${project.title},
  "description": ${project.description},
  "status": ${project.status},
  "created_at": ${project.created_at},
  "updated_at": ${project.updated_at},
  "entity_count": ${project.entity_count},
  "version_count": ${project.version_count},
  "entities": ${entities}
}',
                'entity' => '{
  "uid": ${entity.uid},
  "project": ${entity.project},
  "slug": ${entity.slug},
  "version": ${entity.version},
  "commit": ${entity.commit},
  "active": ${entity.active},
  "parent": ${entity.parent},
  "children": ${entity.children},
  "refs": ${entity.refs},
  "payload": ${entity.payload}
}',
            ],
        ];
    }

    private function buildContextJsonlPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context JSON Lines',
                'Streaming-friendly JSONL export with one entity per line.',
                'Ideal for pipelines that consume entities sequentially; each line is a standalone JSON document.',
                ['example', 'jsonl']
            ),
            'settings' => $this->baseSettings('jsonl'),
            'selection' => $this->baseSelection(true, 1),
            'templates' => [
                'root' => '${meta}
${entities}',
                'project' => '',
                'entity' => '${entity}',
            ],
        ];
    }

    private function buildContextMarkdownUnifiedPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context Markdown (Unified)',
                'Human-readable export with rich metadata and fenced JSON payloads.',
                'Great for analyst briefings or manual review when full context is required.',
                ['example', 'markdown', 'rich']
            ),
            'settings' => $this->baseSettings('markdown'),
            'selection' => $this->baseSelection(true, 1),
            'templates' => [
                'root' => '# ${meta.title}

${projects}
',
                'project' => '## ${project.title}

${project.description}

${entities}
',
                'entity' => '### ${entity.display_name}

- UID: ${entity.uid}
- Version: ${entity.version}
- Active: ${entity.active}

```json
${entity.payload_pretty}
```
',
            ],
        ];
    }

    private function buildContextMarkdownSlimPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context Markdown (Slim)',
                'Concise bullet-point export highlighting entity names for quick scans.',
                'Use when you only need an overview of entities without full payload detail.',
                ['example', 'markdown', 'slim']
            ),
            'settings' => $this->baseSettings('markdown'),
            'selection' => $this->baseSelection(false, 0),
            'templates' => [
                'root' => '# ${meta.title}

${projects}
',
                'project' => '## ${project.title}

${entities}
',
                'entity' => '- ${entity.display_name}
',
            ],
        ];
    }

    private function buildContextMarkdownPlainPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context Markdown (Plain)',
                'Human-readable export without embedded JSON blocks.',
                'Use when you want a narrative context bundle with markdown headings only.',
                ['example', 'markdown', 'plain']
            ),
            'settings' => $this->baseSettings('markdown'),
            'selection' => $this->baseSelection(true, 1),
            'templates' => [
                'root' => <<<'MD'
# ${meta.title}

${projects}
MD,
                'project' => <<<'MD'
## ${project.title}

${entities}
MD,
                'entity' => <<<'MD'
${entity.heading_prefix} ${entity.display_name}

${entity.payload_plain}
MD,
            ],
        ];
    }

    private function buildContextTextPlainPreset(): array
    {
        return [
            'meta' => $this->baseMeta(
                'Context Text (Plain)',
                'Plain text export with simple key/value breakdowns.',
                'Use for lightweight prompts or systems that cannot parse Markdown.',
                ['example', 'text', 'plain']
            ),
            'settings' => $this->baseSettings('text'),
            'selection' => $this->baseSelection(true, 1),
            'templates' => [
                'root' => <<<'TEXT'
Export: ${meta.title}

${projects}
TEXT,
                'project' => <<<'TEXT'
Project: ${project.title}
${entities}
TEXT,
                'entity' => <<<'TEXT'
${entity.indent}- ${entity.display_name}: ${entity.payload_plain}
TEXT,
            ],
        ];
    }

    private function baseMeta(string $title, string $description, string $usage, array $tags = []): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'usage' => $usage,
            'tags' => $tags,
        ];
    }

    private function baseSettings(string $format, bool $nestChildren = false, array $variables = [], bool $includeReferences = true, int $referenceDepth = 1, string $missingPayloadPolicy = 'empty'): array
    {
        if ($referenceDepth < 0) {
            $referenceDepth = 0;
        }

        return [
            'destination' => [
                'path' => null,
                'response' => true,
                'save' => true,
                'format' => $format,
                'nest_children' => $nestChildren,
            ],
            'variables' => $variables,
            'transform' => [
                'whitelist' => [],
                'blacklist' => [],
                'post' => [],
            ],
            'policies' => [
                'references' => [
                    'include' => $includeReferences,
                    'depth' => $includeReferences ? $referenceDepth : 0,
                ],
                'cache' => [
                    'ttl' => 3600,
                    'invalidate_on' => ['hash', 'commit'],
                ],
            ],
            'options' => [
                'missing_payload' => $missingPayloadPolicy,
            ],
        ];
    }

    private function baseSelection(bool $includeReferences, int $depth): array
    {
        if ($depth < 0) {
            $depth = 0;
        }

        return [
            'projects' => ['${project}'],
            'entities' => [],
            'payload_filters' => [],
            'include_references' => [
                'enabled' => $includeReferences,
                'depth' => $includeReferences ? $depth : 0,
                'modes' => ['primary'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $templates
     *
     * @return array<int, string>
     */
    private function collectTemplatePlaceholders(array $templates): array
    {
        $found = [];

        foreach ($templates as $template) {
            if (!is_string($template) || $template === '') {
                continue;
            }

            $count = preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);
            if ($count === false || $count === 0) {
                continue;
            }

            foreach ($matches[1] as $placeholder) {
                $found[$placeholder] = true;
            }
        }

        $placeholders = array_keys($found);
        sort($placeholders, SORT_STRING);

        return $placeholders;
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
