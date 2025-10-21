<?php

declare(strict_types=1);

namespace AavionDB\Modules\Preset;

use InvalidArgumentException;
use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function strtolower;
use function trim;

final class PresetValidator
{
    private const DEFAULT_LAYOUT = 'context-unified-v2';

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
        $definition['selection'] = $this->normaliseSelection($definition['selection'] ?? []);
        $definition['transform'] = $this->normaliseTransform($definition['transform'] ?? []);
        $definition['policies'] = $this->normalisePolicies($definition['policies'] ?? []);
        $definition['placeholders'] = $this->normalisePlaceholders($definition['placeholders'] ?? []);
        $definition['params'] = $this->normaliseParams($definition['params'] ?? []);

        if (!isset($definition['meta']['layout']) || $definition['meta']['layout'] === '') {
            $definition['meta']['layout'] = self::DEFAULT_LAYOUT;
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function normaliseMeta(array $meta): array
    {
        $description = isset($meta['description']) ? trim((string) $meta['description']) : '';
        $usage = isset($meta['usage']) ? trim((string) $meta['usage']) : '';
        $layout = isset($meta['layout']) ? $this->normaliseSlug((string) $meta['layout']) : self::DEFAULT_LAYOUT;
        if ($layout === '') {
            $layout = self::DEFAULT_LAYOUT;
        }

        $meta['description'] = $description;
        $meta['usage'] = $usage;
        $meta['layout'] = $layout;
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
                $entities[] = $this->normaliseFilterDefinition($definition, $index, 'entities');
            }
        }

        $payloadFilters = [];
        if (isset($selection['payload_filters']) && is_array($selection['payload_filters'])) {
            foreach (array_values($selection['payload_filters']) as $index => $definition) {
                $payloadFilters[] = $this->normaliseFilterDefinition($definition, $index, 'payload_filters');
            }
        }

        return [
            'projects' => $projects,
            'entities' => $entities,
            'payload_filters' => $payloadFilters,
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
                $post[] = $this->normaliseFilterDefinition($definition, $index, 'transform.post');
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
     * @param mixed $placeholders
     *
     * @return array<int, string>
     */
    private function normalisePlaceholders($placeholders): array
    {
        if (!is_array($placeholders)) {
            return [];
        }

        $result = [];
        foreach ($placeholders as $placeholder) {
            if (!is_string($placeholder)) {
                continue;
            }

            $key = trim($placeholder);
            if ($key === '') {
                continue;
            }

            $result[$key] = $key;
        }

        return array_values($result);
    }

    /**
     * @param mixed $params
     *
     * @return array<string, array<string, mixed>>
     */
    private function normaliseParams($params): array
    {
        if (!is_array($params)) {
            return [];
        }

        $result = [];

        foreach ($params as $name => $config) {
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Parameter names must be non-empty strings.');
            }

            if (!is_array($config)) {
                $config = ['default' => $config];
            }

            $required = isset($config['required']) ? (bool) $config['required'] : false;
            $default = $config['default'] ?? null;

        if ($default !== null && !is_scalar($default) && !is_array($default)) {
            throw new InvalidArgumentException(sprintf('Parameter "%s" has an unsupported default value.', $name));
        }

        $description = isset($config['description']) ? trim((string) $config['description']) : null;

        $result[$name] = [
            'required' => $required,
            'default' => $default,
                'description' => $description,
            ];
        }

        return $result;
    }

    private function normaliseSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) \preg_replace('/[^a-z0-9\-_.]/', '-', $value);

        return trim($value, '-_.');
    }
}
