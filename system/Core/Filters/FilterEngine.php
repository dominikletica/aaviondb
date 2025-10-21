<?php

declare(strict_types=1);

namespace AavionDB\Core\Filters;

use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Evaluates preset filter definitions against project entities.
 */
final class FilterEngine
{
    private BrainRepository $brains;

    private LoggerInterface $logger;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $payloadCache = [];

    public function __construct(BrainRepository $brains, LoggerInterface $logger)
    {
        $this->brains = $brains;
        $this->logger = $logger;
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     * @param array<string, mixed>             $options
     *
     * @return array{entities: array<int, string>, directives: array<string, mixed>}
     */
    public function selectEntities(string $projectSlug, array $filters, array $options = []): array
    {
        $entities = $this->brains->listEntities($projectSlug);
        $selected = array_keys($entities);
        $directives = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $type = isset($filter['type']) ? strtolower(trim((string) $filter['type'])) : '';
            if ($type === '') {
                continue;
            }

            $config = isset($filter['config']) && is_array($filter['config']) ? $filter['config'] : [];
            $selected = $this->applyFilter($projectSlug, $entities, $selected, $type, $config, $directives, $options);

            if ($selected === []) {
                break;
            }
        }

        return [
            'entities' => array_values(array_unique($selected)),
            'directives' => $directives,
        ];
    }

    /**
     * Checks if a single entity satisfies the provided filters.
     *
     * @param array<int, array<string, mixed>> $filters
     * @param array<string, mixed>             $options
     */
    public function passesFilters(string $projectSlug, string $entitySlug, array $filters, array $options = []): bool
    {
        if ($filters === []) {
            return true;
        }

        $entities = $this->brains->listEntities($projectSlug);
        if (!isset($entities[$entitySlug])) {
            return false;
        }

        $selected = [$entitySlug];
        $directives = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $type = isset($filter['type']) ? strtolower(trim((string) $filter['type'])) : '';
            if ($type === '') {
                continue;
            }

            $config = isset($filter['config']) && is_array($filter['config']) ? $filter['config'] : [];
            $selected = $this->applyFilter($projectSlug, $entities, $selected, $type, $config, $directives, $options);

            if (!in_array($entitySlug, $selected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<int, string>                  $selected
     * @param array<string, mixed>                $config
     * @param array<string, mixed>                $directives
     * @param array<string, mixed>                $options
     *
     * @return array<int, string>
     */
    private function applyFilter(
        string $projectSlug,
        array $entities,
        array $selected,
        string $type,
        array $config,
        array &$directives,
        array $options
    ): array {
        switch ($type) {
            case 'slug_equals':
                $value = $this->normalizeSlug($config['value'] ?? '');
                if ($value === '') {
                    return $selected;
                }

                return isset($entities[$value]) ? [$value] : [];

            case 'slug_in':
                $values = $this->normalizeSlugList($config['values'] ?? []);
                if ($values === []) {
                    return $selected;
                }

                return array_values(array_filter(
                    $selected,
                    static fn (string $slug): bool => in_array($slug, $values, true)
                ));

            case 'parent_contains':
                $needle = strtolower(trim((string) ($config['value'] ?? '')));
                if ($needle === '') {
                    return $selected;
                }

                return array_values(array_filter($selected, static function (string $slug) use ($entities, $needle): bool {
                    $meta = $entities[$slug] ?? [];
                    $path = isset($meta['path_string']) ? strtolower((string) $meta['path_string']) : '';

                    return $path !== '' && str_contains($path, $needle);
                }));

            case 'payload_contains':
                return $this->filterByPayloadContains($projectSlug, $entities, $selected, $config);

            case 'payload_regex':
                return $this->filterByPayloadRegex($projectSlug, $entities, $selected, $config);

            case 'payload_numeric':
                return $this->filterByPayloadNumeric($projectSlug, $entities, $selected, $config);

            case 'payload_missing':
                return $this->filterByPayloadMissing($projectSlug, $entities, $selected, $config);

            case 'include_references':
                $depth = isset($config['depth']) && is_numeric($config['depth'])
                    ? (int) $config['depth']
                    : 1;
                $directives['include_references'] = [
                    'depth' => $depth,
                    'mode' => $config['mode'] ?? 'full',
                ];

                return $selected;

            case 'custom_placeholder':
                $key = isset($config['key']) ? trim((string) $config['key']) : '';
                if ($key !== '' && isset($options['placeholders'][$key]) && is_array($options['placeholders'][$key])) {
                    $resolved = $this->normalizeSlugList($options['placeholders'][$key]);

                    return $resolved === [] ? $selected : array_values(array_filter(
                        $selected,
                        static fn (string $slug): bool => in_array($slug, $resolved, true)
                    ));
                }

                return $selected;

            default:
                $this->logger->debug('FilterEngine received unsupported filter.', [
                    'type' => $type,
                    'config' => $config,
                ]);

                return $selected;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<int, string>                  $selected
     *
     * @return array<int, string>
     */
    private function filterByPayloadContains(string $projectSlug, array $entities, array $selected, array $config): array
    {
        $path = isset($config['field']) ? trim((string) $config['field']) : '';
        if ($path === '') {
            return $selected;
        }

        $expected = [];

        if (isset($config['value'])) {
            $expected[] = $config['value'];
        }

        if (isset($config['values']) && is_array($config['values'])) {
            $expected = [...$expected, ...$config['values']];
        }

        if ($expected === []) {
            return $selected;
        }

        $needles = array_map(static function ($value): string {
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $expected);

        return array_values(array_filter($selected, function (string $slug) use ($projectSlug, $path, $needles): bool {
            $payload = $this->loadActivePayload($projectSlug, $slug);
            if ($payload === null) {
                return false;
            }

            $value = $this->getValueByPath($payload, $path);
            if ($value === null) {
                return false;
            }

            if (is_scalar($value)) {
                return in_array((string) $value, $needles, true);
            }

            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_scalar($entry) && in_array((string) $entry, $needles, true)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<int, string>                  $selected
     *
     * @return array<int, string>
     */
    private function filterByPayloadRegex(string $projectSlug, array $entities, array $selected, array $config): array
    {
        $path = isset($config['field']) ? trim((string) $config['field']) : '';
        $pattern = isset($config['pattern']) ? trim((string) $config['pattern']) : '';

        if ($path === '' || $pattern === '') {
            return $selected;
        }

        return array_values(array_filter($selected, function (string $slug) use ($projectSlug, $path, $pattern): bool {
            $payload = $this->loadActivePayload($projectSlug, $slug);
            if ($payload === null) {
                return false;
            }

            $value = $this->getValueByPath($payload, $path);
            if ($value === null) {
                return false;
            }

            if (is_scalar($value)) {
                return @preg_match($pattern, (string) $value) === 1;
            }

            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_scalar($entry) && @preg_match($pattern, (string) $entry) === 1) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<int, string>                  $selected
     *
     * @return array<int, string>
     */
    private function filterByPayloadNumeric(string $projectSlug, array $entities, array $selected, array $config): array
    {
        $path = isset($config['field']) ? trim((string) $config['field']) : '';
        if ($path === '') {
            return $selected;
        }

        $op = isset($config['op']) ? trim((string) $config['op']) : '==';
        $rawValue = $config['value'] ?? null;

        if (!is_numeric($rawValue)) {
            return $selected;
        }

        $expected = (float) $rawValue;

        return array_values(array_filter($selected, function (string $slug) use ($projectSlug, $path, $op, $expected): bool {
            $payload = $this->loadActivePayload($projectSlug, $slug);
            if ($payload === null) {
                return false;
            }

            $value = $this->getValueByPath($payload, $path);
            if (!is_numeric($value)) {
                return false;
            }

            $actual = (float) $value;

            return match ($op) {
                '==' => $actual == $expected,
                '=' => $actual == $expected,
                '!=' => $actual != $expected,
                '<>' => $actual != $expected,
                '>' => $actual > $expected,
                '<' => $actual < $expected,
                '>=' => $actual >= $expected,
                '<=' => $actual <= $expected,
                default => false,
            };
        }));
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @param array<int, string>                  $selected
     *
     * @return array<int, string>
     */
    private function filterByPayloadMissing(string $projectSlug, array $entities, array $selected, array $config): array
    {
        $path = isset($config['field']) ? trim((string) $config['field']) : '';
        if ($path === '') {
            return $selected;
        }

        $mode = isset($config['mode']) ? strtolower(trim((string) $config['mode'])) : 'is_null';

        return array_values(array_filter($selected, function (string $slug) use ($projectSlug, $path, $mode): bool {
            $payload = $this->loadActivePayload($projectSlug, $slug);
            if ($payload === null) {
                return $mode === 'is_null';
            }

            $value = $this->getValueByPath($payload, $path);

            return match ($mode) {
                'is_null', 'null' => $value === null,
                'empty' => $value === null || $value === '' || $value === [] || $value === false,
                'missing' => $value === null,
                default => $value === null,
            };
        }));
    }

    private function normalizeSlug($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-_.]/', '-', $value) ?? $value;

        return trim((string) $value, '-_.');
    }

    /**
     * @param mixed $values
     *
     * @return array<int, string>
     */
    private function normalizeSlugList($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = array_map(fn ($value): string => $this->normalizeSlug((string) $value), $values);

        return array_values(array_filter($normalized, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadActivePayload(string $projectSlug, string $entitySlug): ?array
    {
        $key = $projectSlug . '/' . $entitySlug;

        if (!array_key_exists($key, $this->payloadCache)) {
            try {
                $record = $this->brains->getEntityVersion($projectSlug, $entitySlug, null);
                $payload = $record['payload'] ?? null;
                $this->payloadCache[$key] = is_array($payload) ? $payload : null;
            } catch (Throwable $exception) {
                $this->payloadCache[$key] = null;
                $this->logger->warning('Failed to load entity payload for filtering.', [
                    'project' => $projectSlug,
                    'entity' => $entitySlug,
                    'exception' => [
                        'message' => $exception->getMessage(),
                        'class' => $exception::class,
                    ],
                ]);
            }
        }

        return $this->payloadCache[$key];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return mixed
     */
    private function getValueByPath(array $payload, string $path)
    {
        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
