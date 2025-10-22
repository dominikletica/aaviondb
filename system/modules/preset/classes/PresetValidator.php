<?php

declare(strict_types=1);

namespace AavionDB\Modules\Preset;

use InvalidArgumentException;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function strtolower;
use function trim;

final class PresetValidator
{
    private const DEFAULT_FORMAT = 'json';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_FORMATS = ['json', 'jsonl', 'markdown', 'text'];

    /**
     * Validates and normalises a preset definition.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function validate(array $definition): array
    {
        $definition['meta'] = $this->normaliseMeta($definition['meta'] ?? []);
        $definition['settings'] = $this->normaliseSettings($definition['settings'] ?? []);
        $definition['selection'] = $this->normaliseSelection($definition['selection'] ?? []);
        $definition['templates'] = $this->normaliseTemplates($definition['templates'] ?? []);

        return $definition;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function normaliseMeta(array $meta): array
    {
        $meta['title'] = isset($meta['title']) ? trim((string) $meta['title']) : '';
        $meta['description'] = isset($meta['description']) ? trim((string) $meta['description']) : '';
        $meta['usage'] = isset($meta['usage']) ? trim((string) $meta['usage']) : '';
        $meta['read_only'] = isset($meta['read_only']) ? (bool) $meta['read_only'] : false;

        if (isset($meta['immutable'])) {
            $meta['immutable'] = (bool) $meta['immutable'];
        }

        if (isset($meta['tags']) && is_array($meta['tags'])) {
            $meta['tags'] = array_values(array_filter(array_map(
                fn ($value): string => $this->normaliseSlug((string) $value),
                $meta['tags']
            ), static fn (string $tag): bool => $tag !== ''));
        } else {
            $meta['tags'] = [];
        }

        if (isset($meta['uuid']) && !is_string($meta['uuid'])) {
            unset($meta['uuid']);
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normaliseSettings(array $settings): array
    {
        return [
            'destination' => $this->normaliseDestination($settings['destination'] ?? []),
            'variables' => $this->normaliseVariables($settings['variables'] ?? []),
            'transform' => $this->normaliseTransform($settings['transform'] ?? []),
            'policies' => $this->normalisePolicies($settings['policies'] ?? []),
            'options' => $this->normaliseOptions($settings['options'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $selection
     *
     * @return array<string, mixed>
     */
    private function normaliseSelection(array $selection): array
    {
        $projects = [];
        if (isset($selection['projects']) && is_array($selection['projects'])) {
            $projects = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $selection['projects']
            ), static fn (string $value): bool => $value !== ''));
        }

        if ($projects === []) {
            $projects = ['${project}'];
        }

        $entities = [];
        if (isset($selection['entities']) && is_array($selection['entities'])) {
            foreach (array_values($selection['entities']) as $index => $definition) {
                $entities[] = $this->normaliseFilterDefinition($definition, $index, 'selection.entities');
            }
        }

        $payloadFilters = [];
        if (isset($selection['payload_filters']) && is_array($selection['payload_filters'])) {
            foreach (array_values($selection['payload_filters']) as $index => $definition) {
                $payloadFilters[] = $this->normaliseFilterDefinition($definition, $index, 'selection.payload_filters');
            }
        }

        $includeReferences = $selection['include_references'] ?? [];
        if (!is_array($includeReferences)) {
            $includeReferences = [];
        }

        $modes = [];
        if (isset($includeReferences['modes']) && is_array($includeReferences['modes'])) {
            $modes = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $includeReferences['modes']
            ), static fn (string $mode): bool => $mode !== ''));
        }

        $depth = isset($includeReferences['depth']) && is_numeric($includeReferences['depth'])
            ? (int) $includeReferences['depth']
            : 0;
        if ($depth < 0) {
            $depth = 0;
        }

        return [
            'projects' => $projects,
            'entities' => $entities,
            'payload_filters' => $payloadFilters,
            'include_references' => [
                'enabled' => isset($includeReferences['enabled'])
                    ? (bool) $includeReferences['enabled']
                    : ($depth > 0 || $modes !== []),
                'depth' => $depth,
                'modes' => $modes,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|string $definition
     *
     * @return array<string, mixed>
     */
    private function normaliseFilterDefinition($definition, int $index, string $section): array
    {
        if (is_string($definition)) {
            $value = trim($definition);
            if ($value === '') {
                throw new InvalidArgumentException(sprintf(
                    'Filter #%d in "%s" must not be an empty string.',
                    $index,
                    $section
                ));
            }

            return [
                'type' => 'slug_equals',
                'config' => ['value' => $value],
            ];
        }

        if (!is_array($definition)) {
            throw new InvalidArgumentException(sprintf(
                'Filter #%d in "%s" must be an object.',
                $index,
                $section
            ));
        }

        $type = isset($definition['type']) ? trim((string) $definition['type']) : '';
        if ($type === '') {
            throw new InvalidArgumentException(sprintf(
                'Filter #%d in "%s" is missing the "type" field.',
                $index,
                $section
            ));
        }

        $config = isset($definition['config']) && is_array($definition['config'])
            ? $definition['config']
            : [];

        return [
            'type' => strtolower($type),
            'config' => $config,
        ];
    }

    /**
     * @param array<string, mixed>|null $destination
     *
     * @return array<string, mixed>
     */
    private function normaliseDestination($destination): array
    {
        if (!is_array($destination)) {
            $destination = [];
        }

        $path = isset($destination['path']) ? trim((string) $destination['path']) : '';
        $format = isset($destination['format']) ? strtolower(trim((string) $destination['format'])) : self::DEFAULT_FORMAT;
        if ($format === '') {
            $format = self::DEFAULT_FORMAT;
        }

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported destination format "%s".', $destination['format'] ?? $format));
        }

        return [
            'path' => $path === '' ? null : $path,
            'response' => isset($destination['response']) ? (bool) $destination['response'] : true,
            'save' => isset($destination['save']) ? (bool) $destination['save'] : true,
            'format' => $format,
            'nest_children' => isset($destination['nest_children']) ? (bool) $destination['nest_children'] : false,
        ];
    }

    /**
     * @param mixed $variables
     *
     * @return array<string, array<string, mixed>>
     */
    private function normaliseVariables($variables): array
    {
        if (!is_array($variables)) {
            return [];
        }

        $result = [];
        foreach ($variables as $name => $config) {
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Variable names must be non-empty strings.');
            }

            if (!is_array($config)) {
                $config = ['default' => $config];
            }

            $required = isset($config['required']) ? (bool) $config['required'] : false;
            $default = $config['default'] ?? null;

            if ($default !== null && !is_scalar($default) && !is_array($default)) {
                throw new InvalidArgumentException(sprintf('Variable "%s" has an unsupported default value.', $name));
            }

            $description = isset($config['description']) ? trim((string) $config['description']) : null;
            $type = isset($config['type']) ? $this->normaliseVariableType((string) $config['type']) : 'text';

            $result[$name] = [
                'required' => $required,
                'default' => $default,
                'description' => $description,
                'type' => $type,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $transform
     *
     * @return array<string, mixed>
     */
    private function normaliseTransform(array $transform): array
    {
        $whitelist = [];
        if (isset($transform['whitelist']) && is_array($transform['whitelist'])) {
            $whitelist = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $transform['whitelist']
            ), static fn (string $value): bool => $value !== ''));
        }

        $blacklist = [];
        if (isset($transform['blacklist']) && is_array($transform['blacklist'])) {
            $blacklist = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $transform['blacklist']
            ), static fn (string $value): bool => $value !== ''));
        }

        $post = [];
        if (isset($transform['post']) && is_array($transform['post'])) {
            foreach (array_values($transform['post']) as $index => $definition) {
                $post[] = $this->normaliseFilterDefinition($definition, $index, 'settings.transform.post');
            }
        }

        return [
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'post' => $post,
        ];
    }

    /**
     * @param array<string, mixed> $policies
     *
     * @return array<string, mixed>
     */
    private function normalisePolicies(array $policies): array
    {
        $references = isset($policies['references']) && is_array($policies['references'])
            ? $policies['references']
            : [];

        $referencesDepth = isset($references['depth']) && is_numeric($references['depth'])
            ? (int) $references['depth']
            : 0;
        if ($referencesDepth < 0) {
            $referencesDepth = 0;
        }

        $referencesInclude = isset($references['include'])
            ? (bool) $references['include']
            : true;

        $cache = isset($policies['cache']) && is_array($policies['cache']) ? $policies['cache'] : [];
        $ttl = isset($cache['ttl']) && is_numeric($cache['ttl'])
            ? (int) $cache['ttl']
            : 0;
        if ($ttl < 0) {
            $ttl = 0;
        }

        $invalidateOn = [];
        if (isset($cache['invalidate_on']) && is_array($cache['invalidate_on'])) {
            $invalidateOn = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $cache['invalidate_on']
            ), static fn (string $value): bool => $value !== ''));
        } elseif (isset($cache['invalidate_on']) && is_string($cache['invalidate_on'])) {
            $invalidateOn = [trim($cache['invalidate_on'])];
        }

        return [
            'references' => [
                'include' => $referencesInclude,
                'depth' => $referencesDepth,
            ],
            'cache' => [
                'ttl' => $ttl,
                'invalidate_on' => $invalidateOn,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normaliseOptions(array $options): array
    {
        $missingPolicy = isset($options['missing_payload']) ? strtolower(trim((string) $options['missing_payload'])) : 'empty';
        if ($missingPolicy === '') {
            $missingPolicy = 'empty';
        }

        if (!in_array($missingPolicy, ['empty', 'skip'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported options.missing_payload value "%s".', $options['missing_payload'] ?? $missingPolicy));
        }

        return [
            'missing_payload' => $missingPolicy,
        ];
    }

    /**
     * @param array<string, mixed> $templates
     *
     * @return array<string, string>
     */
    private function normaliseTemplates(array $templates): array
    {
        $root = $this->normaliseTemplateString($templates['root'] ?? null, 'root', true);
        $project = $this->normaliseTemplateString($templates['project'] ?? '', 'project', false);
        $entity = $this->normaliseTemplateString($templates['entity'] ?? null, 'entity', true);

        return [
            'root' => $root,
            'project' => $project,
            'entity' => $entity,
        ];
    }

    private function normaliseTemplateString($value, string $label, bool $required): string
    {
        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException(sprintf('Template "%s" must be provided.', $label));
            }

            return '';
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Template "%s" must be a string.', $label));
        }

        if ($required && trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Template "%s" must not be empty.', $label));
        }

        return $value;
    }

    private function normaliseSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) \preg_replace('/[^a-z0-9\-_.]/', '-', $value);

        return trim($value, '-_.');
    }

    private function normaliseVariableType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return 'text';
        }

        if (in_array($normalized, ['integer', 'int'], true)) {
            return 'int';
        }

        if (in_array($normalized, ['boolean', 'bool'], true)) {
            return 'bool';
        }

        if ($normalized === 'csv') {
            return 'comma_list';
        }

        if (in_array($normalized, ['number', 'float', 'double'], true)) {
            return 'number';
        }

        if ($normalized === 'string') {
            return 'text';
        }

        if (in_array($normalized, ['text', 'array', 'object', 'json', 'comma_list'], true)) {
            return $normalized;
        }

        throw new InvalidArgumentException(sprintf('Unsupported variable type "%s".', $type));
    }
}
