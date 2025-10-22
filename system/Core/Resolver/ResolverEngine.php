<?php

declare(strict_types=1);

namespace AavionDB\Core\Resolver;

use AavionDB\Core\Filters\FilterEngine;
use AavionDB\Core\Storage\BrainRepository;
use JsonSerializable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use function array_fill;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Resolves inline reference/query shortcodes embedded in entity payloads.
 */
final class ResolverEngine
{
    private const STRIP_PATTERN = '/\[(ref|query)([^\]]*)\](.*?)\[\/\1\]/is';

    private const SHORTCODE_PATTERN = '/\[(ref|query)([^\]\/]*?)\]/i';

    private const DEFAULT_SEPARATOR = "\n";

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private FilterEngine $filters;

    private int $maxDepth;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $entityCache = [];

    public function __construct(
        BrainRepository $brains,
        LoggerInterface $logger,
        ?FilterEngine $filters = null,
        int $maxDepth = 6
    ) {
        $this->brains = $brains;
        $this->logger = $logger;
        $this->filters = $filters ?? new FilterEngine($brains, $logger);
        $this->maxDepth = $maxDepth;
    }

    /**
     * Resolve shortcodes inside the provided payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function resolvePayload(array $payload, ResolverContext $context): array
    {
        return $this->resolveValue($payload, $context, [
            'chain' => [],
            'depth' => 0,
        ]);
    }

    /**
     * Remove rendered shortcode output (leaving the original marker intact).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function stripPayload(array $payload): array
    {
        return $this->stripValue($payload);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function stripValue($value)
    {
        if (is_string($value)) {
            return $this->stripResolvedString($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $entry) {
                $result[$key] = $this->stripValue($entry);
            }

            return $result;
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function resolveValue($value, ResolverContext $context, array $state)
    {
        $depth = $state['depth'] ?? 0;
        if ($depth > $this->maxDepth) {
            return $value;
        }

        if (is_string($value)) {
            return $this->resolveString($value, $context, $state);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $entry) {
                $nextState = $state;
                $nextState['depth'] = $depth;
                $result[$key] = $this->resolveValue($entry, $context, $nextState);
            }

            return $result;
        }

        return $value;
    }

    private function stripResolvedString(string $value): string
    {
        $stripped = preg_replace(self::STRIP_PATTERN, '[\\1\\2]', $value);

        return $stripped ?? $value;
    }

    private function resolveString(string $value, ResolverContext $context, array $state): string
    {
        $clean = $this->stripResolvedString($value);
        $depth = $state['depth'] ?? 0;

        $resolved = preg_replace_callback(
            self::SHORTCODE_PATTERN,
            function (array $matches) use (&$state, $context, $depth): string {
                $tag = strtolower(trim($matches[1]));
                $rawArgs = trim($matches[2] ?? '');

                if ($depth >= $this->maxDepth) {
                    return $matches[0];
                }

                try {
                    $content = $tag === 'ref'
                        ? $this->resolveRef($rawArgs, $context, $state, $depth + 1)
                        : $this->resolveQuery($rawArgs, $context, $state, $depth + 1);

                    return sprintf('[%s%s]%s[/%s]', $tag, $rawArgs !== '' ? ' ' . $rawArgs : '', $content, $tag);
                } catch (Throwable $exception) {
                    $this->logger->warning('Failed to resolve shortcode.', [
                        'tag' => $tag,
                        'args' => $rawArgs,
                        'exception' => [
                            'message' => $exception->getMessage(),
                            'type' => $exception::class,
                        ],
                    ]);

                    return sprintf('[%s%s]<unresolved: %s>[/%s]', $tag, $rawArgs !== '' ? ' ' . $rawArgs : '', $exception->getMessage(), $tag);
                }
            },
            $clean
        );

        if ($resolved === null) {
            return $value;
        }

        return $resolved;
    }

    private function resolveRef(string $rawArgs, ResolverContext $context, array &$state, int $depth): string
    {
        $parts = $this->splitArguments($rawArgs);
        if ($parts === []) {
            throw new RuntimeException('Reference target is missing.');
        }

        $pathSegments = [];
        $optionsSegments = [];

        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_contains($segment, '=') && !preg_match('/^[^=]+=[^=]+$/', $segment)) {
                // "=" used more than once; treat as part of path.
                $pathSegments[] = $segment;
                continue;
            }

            if (preg_match('/^[^=]+=.*$/', $segment) === 1) {
                $optionsSegments[] = $segment;
                continue;
            }

            $pathSegments[] = $segment;
        }

        $targetPath = $this->resolveTargetPath($pathSegments, $context);
        $options = $this->parseOptions($optionsSegments, $context);

        $chain = $state['chain'] ?? [];
        $nodeId = $targetPath['uid'] . ':' . ($targetPath['path'] ?? '');
        if (in_array($nodeId, $chain, true)) {
            return '<cycle>';
        }

        $chain[] = $nodeId;
        $state['chain'] = $chain;
        $formatted = null;

        try {
            $record = $this->loadEntityRecord($targetPath['project'], $targetPath['entity'], $targetPath['reference']);
            $value = $this->extractFromRecord($record, $targetPath['path'] ?? '');

            $formatted = $this->formatValue($value, $options, $context, $state, $depth, $record);
        } finally {
            array_pop($chain);
            $state['chain'] = $chain;
        }

        if (is_string($formatted)) {
            return $formatted;
        }

        return $this->encodeJson($formatted);
    }

    private function resolveQuery(string $rawArgs, ResolverContext $context, array &$state, int $depth): string
    {
        $segments = $this->splitArguments($rawArgs);
        $options = $this->parseOptions($segments, $context);

        $projects = $this->determineProjects($options, $context);

        $where = isset($options['where']) ? (string) $options['where'] : '';
        $conditions = $where !== '' ? $this->parseWhereClause($where, $context) : [];

        $select = isset($options['select']) ? (string) $options['select'] : 'payload';
        $format = isset($options['format']) ? strtolower((string) $options['format']) : 'json';
        $limit = isset($options['limit']) && is_numeric($options['limit']) ? (int) $options['limit'] : null;
        $offset = isset($options['offset']) && is_numeric($options['offset']) ? (int) $options['offset'] : 0;
        $sort = isset($options['sort']) ? (string) $options['sort'] : '';
        $template = isset($options['template']) ? (string) $options['template'] : '{value}';
        $separator = isset($options['separator']) ? (string) $options['separator'] : self::DEFAULT_SEPARATOR;

        $entries = [];

        foreach ($projects as $project) {
            $entities = $this->brains->listEntities($project);
            foreach ($entities as $slug => $meta) {
                $record = $this->loadEntityRecord($project, $slug, null);
                if (!$this->matchesConditions($project, $slug, $meta, $record, $conditions, $context)) {
                    continue;
                }

                $value = $this->extractFromRecord($record, $select);

                $entries[] = [
                    'uid' => $project . '.' . $slug,
                    'project' => $project,
                    'slug' => $slug,
                    'record' => $record,
                    'value' => $value,
                ];
            }
        }

        if ($sort !== '') {
            $entries = $this->sortEntries($entries, $sort);
        }

        if ($offset > 0) {
            $entries = array_slice($entries, $offset);
        }

        if ($limit !== null && $limit >= 0) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $this->renderQueryResult($entries, $format, $template, $separator, $context, $state, $depth);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function renderQueryResult(
        array $entries,
        string $format,
        string $template,
        string $separator,
        ResolverContext $context,
        array $state,
        int $depth
    ): string {
        $augmentedEntries = [];
        foreach ($entries as $entry) {
            $record = $this->enrichRecordWithUrls($entry['record'], $context);
            $entry['record'] = $record;
            $entry['url'] = $record['url'] ?? null;
            $entry['url_relative'] = $record['url_relative'] ?? null;
            $entry['url_absolute'] = $record['url_absolute'] ?? null;
            $augmentedEntries[] = $entry;
        }

        $entries = $augmentedEntries;

        if ($format === 'markdown' || $format === 'plain' || $format === 'text') {
            $lines = [];
            foreach ($entries as $entry) {
                $line = $this->renderTemplate($template, $entry);
                $line = $this->resolveString($line, $context, ['chain' => $state['chain'] ?? [], 'depth' => $depth]);
                $lines[] = $line;
            }

            $glue = $format === 'markdown' ? self::DEFAULT_SEPARATOR : $separator;

            return implode($glue, $lines);
        }

        if ($format === 'raw') {
            return $this->encodeJson(array_map(static function (array $entry) {
                return [
                    'uid' => $entry['uid'],
                    'project' => $entry['project'],
                    'slug' => $entry['slug'],
                    'url' => $entry['url_relative'] ?? $entry['url'] ?? null,
                    'url_absolute' => $entry['url_absolute'] ?? null,
                    'value' => $entry['value'],
                ];
            }, $entries));
        }

        // JSON output retains the full record for maximum context richness.
        return $this->encodeJson(array_map(static function (array $entry) {
            return [
                'uid' => $entry['uid'],
                'project' => $entry['project'],
                'slug' => $entry['slug'],
                'record' => $entry['record'],
                'url' => $entry['url_relative'] ?? $entry['url'] ?? null,
                'url_absolute' => $entry['url_absolute'] ?? null,
                'value' => $entry['value'],
            ];
        }, $entries));
    }

    /**
     * @param array<int, array{uid: string, project: string, slug: string, record: array<string, mixed>, value: mixed}> $entries
     *
     * @return array<int, array{uid: string, project: string, slug: string, record: array<string, mixed>, value: mixed}>
     */
    private function sortEntries(array $entries, string $sort): array
    {
        $sort = trim($sort);
        if ($sort === '') {
            return $entries;
        }

        $direction = 'asc';
        if (str_ends_with(strtolower($sort), ' desc')) {
            $direction = 'desc';
            $sort = trim(substr($sort, 0, -5));
        } elseif (str_ends_with(strtolower($sort), ' asc')) {
            $sort = trim(substr($sort, 0, -4));
        }

        usort($entries, function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $this->extractFromRecord($left['record'], $sort);
            $rightValue = $this->extractFromRecord($right['record'], $sort);

            $comparison = $this->compareValues($leftValue, $rightValue);

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $entries;
    }

    private function compareValues($left, $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            $difference = (float) $left - (float) $right;

            if ($difference === 0.0) {
                return 0;
            }

            return $difference > 0 ? 1 : -1;
        }

        $leftString = $this->stringify($left);
        $rightString = $this->stringify($right);

        if ($leftString === $rightString) {
            return 0;
        }

        return $leftString < $rightString ? -1 : 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEntityRecord(string $project, string $entity, ?string $reference): array
    {
        $cacheKey = $project . '/' . $entity . '/' . ($reference ?? '_active_');
        if (!array_key_exists($cacheKey, $this->entityCache)) {
            $version = $this->brains->getEntityVersion($project, $entity, $reference);
            $report = $this->brains->entityReport($project, $entity, true);
            $pathString = isset($report['path_string']) && is_string($report['path_string'])
                ? trim($report['path_string'])
                : '';
            $pathSegments = $pathString !== ''
                ? array_values(array_filter(explode('/', $pathString), static fn ($segment): bool => $segment !== ''))
                : [];

            $this->entityCache[$cacheKey] = [
                'project' => $project,
                'entity' => $entity,
                'version' => $version['version'] ?? null,
                'commit' => $version['commit'] ?? null,
                'status' => $version['status'] ?? null,
                'hash' => $version['hash'] ?? null,
                'payload' => isset($version['payload']) && is_array($version['payload'])
                    ? $version['payload']
                    : [],
                'meta' => $version,
                'path_string' => $pathString,
                'path_segments' => $pathSegments,
            ];
        }

        return $this->entityCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return mixed
     */
    private function extractFromRecord(array $record, string $path)
    {
        $path = trim($path);
        if ($path === '' || $path === 'payload') {
            return $record['payload'];
        }

        $root = $record;

        if (str_starts_with($path, 'payload.')) {
            $root = $record['payload'];
            $path = substr($path, 8);
        } elseif (str_starts_with($path, 'meta.')) {
            $root = $record['meta'];
            $path = substr($path, 5);
        }

        return $this->getValueByPath($root, $path);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $source
     *
     * @return mixed
     */
    private function getValueByPath($source, string $path)
    {
        if ($path === '') {
            return $source;
        }

        $segments = $this->normalisePathSegments($path);
        $current = $source;

        foreach ($segments as $segment) {
            if (is_array($segment)) {
                $index = $segment['index'];
                if (!is_array($current) || !array_key_exists($index, $current)) {
                    return null;
                }
                $current = $current[$index];
                continue;
            }

            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<int, string|array{index: int}>
     */
    private function normalisePathSegments(string $path): array
    {
        $segments = [];
        $buffer = '';
        $chars = str_split($path);
        $insideIndex = false;

        foreach ($chars as $char) {
            if ($char === '.' && !$insideIndex) {
                if ($buffer !== '') {
                    $segments[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            if ($char === '[') {
                if ($buffer !== '') {
                    $segments[] = $buffer;
                    $buffer = '';
                }
                $insideIndex = true;
                continue;
            }

            if ($char === ']') {
                if ($insideIndex && $buffer !== '') {
                    $segments[] = ['index' => (int) $buffer];
                    $buffer = '';
                }
                $insideIndex = false;
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            if ($insideIndex) {
                $segments[] = ['index' => (int) $buffer];
            } else {
                $segments[] = $buffer;
            }
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function enrichRecordWithUrls(array $record, ResolverContext $context): array
    {
        $slug = $record['entity'] ?? $record['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            return $record;
        }

        $segments = $record['path_segments'] ?? [];
        if (is_string($segments)) {
            $segments = $segments !== ''
                ? array_values(array_filter(explode('/', $segments), static fn ($segment): bool => $segment !== ''))
                : [];
        }

        if (!is_array($segments)) {
            $segments = [];
        }

        $targetSegments = array_values(array_filter($segments, static fn ($segment): bool => (string) $segment !== ''));
        $targetSegments[] = $slug;

        $absolute = '/' . implode('/', $targetSegments);

        $callerPath = $context->path();
        $relative = $absolute;

        if ($callerPath !== null) {
            $callerSegments = $this->segmentsFromPath($callerPath, $context->entity());
            $relativeSegments = $this->buildRelativeSegments($callerSegments, $targetSegments);

            if ($relativeSegments === []) {
                $relative = './';
            } else {
                $relative = implode('/', $relativeSegments);
            }
        }

        $record['url_absolute'] = $absolute;
        $record['url_relative'] = $relative;
        $record['url'] = $callerPath === null ? $absolute : $relative;

        return $record;
    }

    /**
     * @return array<int, string>
     */
    private function segmentsFromPath(?string $path, string $entitySlug): array
    {
        $segments = [];

        if (is_string($path) && $path !== '') {
            $segments = array_values(array_filter(explode('/', $path), static fn ($segment): bool => $segment !== ''));
        }

        $segments[] = $entitySlug;

        return $segments;
    }

    /**
     * @param array<int, string> $from
     * @param array<int, string> $to
     *
     * @return array<int, string>
     */
    private function buildRelativeSegments(array $from, array $to): array
    {
        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && $from[$common] === $to[$common]) {
            $common++;
        }

        $ups = array_fill(0, count($from) - $common, '..');
        $downs = array_slice($to, $common);

        return array_values(array_merge($ups, $downs));
    }

    /**
     * @param array<int, string> $segments
     */
    private function resolveTargetPath(array $segments, ResolverContext $context): array
    {
        if ($segments === []) {
            throw new RuntimeException('Reference target is invalid.');
        }

        $targetRaw = array_shift($segments);
        $targetRaw = $this->replacePlaceholders($targetRaw, $context);

        if ($targetRaw === '' || $targetRaw === null) {
            throw new RuntimeException('Reference target is empty.');
        }

        $targetRaw = trim((string) $targetRaw);
        if (str_starts_with($targetRaw, '@')) {
            $targetRaw = substr($targetRaw, 1);
        } else {
            $targetRaw = $context->project() . '.' . $targetRaw;
        }

        $pathExtra = [];
        foreach ($segments as $segment) {
            $segment = trim($this->replacePlaceholders($segment, $context));
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $segment) === 1) {
                $pathExtra[] = '[' . $segment . ']';
                continue;
            }

            if (!str_starts_with($segment, '.') && !str_starts_with($segment, '[')) {
                $pathExtra[] = '.' . $segment;
                continue;
            }

            $pathExtra[] = $segment;
        }

        $firstDot = strpos($targetRaw, '.');
        if ($firstDot === false) {
            throw new RuntimeException('Reference target must include entity slug.');
        }

        $project = substr($targetRaw, 0, $firstDot);
        $rest = substr($targetRaw, $firstDot + 1);

        $secondDot = strpos($rest, '.');

        $entityWithReference = $secondDot === false ? $rest : substr($rest, 0, $secondDot);
        $remainingPath = $secondDot === false ? '' : substr($rest, $secondDot + 1);

        $reference = null;
        $entity = $entityWithReference;

        if (preg_match('/(.+)([@#])([A-Za-z0-9_-]+)$/', $entityWithReference, $matches) === 1) {
            $entity = $matches[1];
            $reference = $matches[3];
        }

        $path = $remainingPath;
        if ($pathExtra !== []) {
            $path .= implode('', $pathExtra);
        }

        return [
            'project' => $project,
            'entity' => $entity,
            'reference' => $reference,
            'path' => $path,
            'uid' => $project . '.' . $entity,
        ];
    }

    /**
     * @param array<int, string> $segments
     *
     * @return array<string, mixed>
     */
    private function parseOptions(array $segments, ResolverContext $context): array
    {
        $options = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $segments = explode('=', $segment, 2);
            $key = trim($segments[0]);
            $value = isset($segments[1]) ? trim($segments[1]) : true;

            $resolvedValue = is_string($value) ? $this->normaliseOptionValue($value, $context) : $value;
            $options[strtolower($key)] = $resolvedValue;
        }

        return $options;
    }

    /**
     * @param array<int, string> $conditions
     *
     * @return array<int, array{field: string, operator: string, value: mixed}>
     */
    private function parseWhereClause(string $where, ResolverContext $context): array
    {
        $clauses = preg_split('/[;]+/', $where) ?: [];
        $parsed = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            if (preg_match('/^(?<field>[A-Za-z0-9._-]+)\s*(?<operator>>=|<=|!=|<>|=|==|>|<|contains|!contains|in|not\s+in|~|matches|regex)\s*(?<value>.+)$/i', $clause, $matches) !== 1) {
                continue;
            }

            $field = $this->replacePlaceholders(trim($matches['field']), $context);
            $operator = strtolower(trim($matches['operator']));
            $rawValue = trim($matches['value']);

            $value = $this->parseClauseValue($rawValue, $context);

            $parsed[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $parsed;
    }

    private function parseClauseValue(string $value, ResolverContext $context)
    {
        $value = trim($this->replacePlaceholders($value, $context));

        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, '\'') && str_ends_with($value, '\''))) {
            return substr($value, 1, -1);
        }

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $content = substr($value, 1, -1);
            $items = array_map('trim', explode(',', $content));
            return array_values(array_filter($items, static fn ($entry): bool => $entry !== ''));
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        if (strtolower($value) === 'true') {
            return true;
        }

        if (strtolower($value) === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $record
     * @param array<int, array{field: string, operator: string, value: mixed}> $conditions
     */
    private function matchesConditions(
        string $project,
        string $entity,
        array $meta,
        array $record,
        array $conditions,
        ResolverContext $context
    ): bool {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = strtolower($condition['field']);
            $value = $this->resolveFieldValue($field, $meta, $record);

            if (!$this->compareCondition($value, $condition['operator'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }

    private function resolveFieldValue(string $field, array $meta, array $record)
    {
        if ($field === 'slug') {
            return $meta['slug'] ?? null;
        }

        if ($field === 'project') {
            return $record['project'] ?? null;
        }

        if ($field === 'parent') {
            return $meta['parent'] ?? null;
        }

        if (str_starts_with($field, 'payload.')) {
            return $this->getValueByPath($record['payload'], substr($field, 8));
        }

        if (str_starts_with($field, 'meta.')) {
            return $this->getValueByPath($record['meta'], substr($field, 5));
        }

        return $this->getValueByPath($record, $field);
    }

    private function compareCondition($left, string $operator, $right): bool
    {
        switch ($operator) {
            case '=':
            case '==':
                if (is_array($right)) {
                    return in_array($left, $right, true);
                }
                return $left == $right;
            case '!=':
            case '<>':
                if (is_array($right)) {
                    return !in_array($left, $right, true);
                }
                return $left != $right;
            case '>':
                return is_numeric($left) && is_numeric($right) && $left > $right;
            case '<':
                return is_numeric($left) && is_numeric($right) && $left < $right;
            case '>=':
                return is_numeric($left) && is_numeric($right) && $left >= $right;
            case '<=':
                return is_numeric($left) && is_numeric($right) && $left <= $right;
            case 'contains':
                return $this->containsValue($left, $right);
            case '!contains':
                return !$this->containsValue($left, $right);
            case 'in':
                return is_array($right) ? in_array($left, $right, true) : false;
            case 'not in':
                return is_array($right) ? !in_array($left, $right, true) : true;
            case '~':
            case 'matches':
            case 'regex':
                if (!is_string($right) || $right === '') {
                    return false;
                }
                $pattern = $right;
                if (@preg_match($pattern, '') === false) {
                    return false;
                }
                return is_string($left) && preg_match($pattern, $left) === 1;
        }

        return false;
    }

    private function containsValue($haystack, $needle): bool
    {
        if (is_array($haystack)) {
            if (is_array($needle)) {
                foreach ($needle as $entry) {
                    if (in_array($entry, $haystack, true)) {
                        return true;
                    }
                }
                return false;
            }

            return in_array($needle, $haystack, true);
        }

        if (is_string($haystack) && is_string($needle)) {
            return str_contains($haystack, $needle);
        }

        return false;
    }

    private function formatValue($value, array $options, ResolverContext $context, array $state, int $depth, array $record)
    {
        $record = $this->enrichRecordWithUrls($record, $context);

        if (is_scalar($value) || $value === null) {
            return $this->stringify($value);
        }

        if (!is_array($value)) {
            if ($value instanceof JsonSerializable) {
                return $this->encodeJson($value->jsonSerialize());
            }

            return (string) $value;
        }

        $format = isset($options['format']) ? strtolower((string) $options['format']) : 'json';
        $template = isset($options['template']) ? (string) $options['template'] : '{value}';
        $separator = isset($options['separator']) ? (string) $options['separator'] : self::DEFAULT_SEPARATOR;

        if ($format === 'plain' || $format === 'markdown') {
            $items = [];
            foreach ($value as $item) {
                $line = $this->renderTemplate($template, [
                    'value' => $item,
                    'record' => $record,
                ]);
                $items[] = $this->resolveString($line, $context, ['chain' => $state['chain'] ?? [], 'depth' => $depth]);
            }

            return implode($separator, $items);
        }

        return $value;
    }

    private function renderTemplate(string $template, array $data): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9._-]+)\}/', function (array $matches) use ($data): string {
            $path = $matches[1];
            if ($path === 'value') {
                return $this->stringify($data['value'] ?? null);
            }

            if (str_starts_with($path, 'record.')) {
                return $this->stringify($this->getValueByPath($data['record'] ?? [], substr($path, 7)));
            }

            return $this->stringify($this->getValueByPath($data, $path));
        }, $template) ?? $template;
    }

    /**
     * @return array<int, string>
     */
    private function splitArguments(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $segments = [];
        $current = '';
        $length = strlen($raw);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote !== null) {
                if ($char === $quote && $raw[$i - 1] !== '\\') {
                    $quote = null;
                }
                $current .= $char;
                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '|') {
                $segments[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $segments[] = trim($current);
        }

        return array_values(array_filter($segments, static fn ($segment) => $segment !== ''));
    }

    private function encodeJson($value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '';
    }

    private function stringify($value): string
    {
        if ($value === null || $value === true || $value === false || is_numeric($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_array($value)) {
            return $this->encodeJson($value);
        }

        return (string) $value;
    }

    /**
     * @return array<int, string>
     */
    private function determineProjects(array $options, ResolverContext $context): array
    {
        $projects = [];

        if (isset($options['projects'])) {
            $projects = is_array($options['projects'])
                ? $options['projects']
                : array_map('trim', explode(',', (string) $options['projects']));
        } elseif (isset($options['project'])) {
            $projects = array_map('trim', explode(',', (string) $options['project']));
        } else {
            $projects = [$context->project()];
        }

        $projects = array_values(array_filter(array_map(
            static fn ($entry): string => trim((string) $entry),
            $projects
        ), static fn (string $entry): bool => $entry !== ''));

        if ($projects === []) {
            $projects = [$context->project()];
        }

        return $projects;
    }

    private function normaliseOptionValue(string $value, ResolverContext $context)
    {
        $value = trim($this->replacePlaceholders($value, $context));

        if ($value === '') {
            return '';
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, '\'') && str_ends_with($value, '\''))) {
            return substr($value, 1, -1);
        }

        if (strtolower($value) === 'true') {
            return true;
        }

        if (strtolower($value) === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        if (str_contains($value, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($entry): bool => $entry !== ''));
        }

        return $value;
    }

    private function replacePlaceholders(string $value, ResolverContext $context): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace_callback('/\$\{([A-Za-z0-9._-]+)\}/', function (array $matches) use ($context): string {
            $key = strtolower($matches[1]);

            if ($key === 'project') {
                return $context->project();
            }

            if ($key === 'entity') {
                return $context->entity();
            }

            if ($key === 'uid') {
                return $context->uid();
            }

            if ($key === 'version') {
                return (string) ($context->version() ?? '');
            }

            if (str_starts_with($key, 'param.')) {
                $param = substr($key, 6);
                $params = $context->params();
                if (array_key_exists($param, $params)) {
                    return $this->stringify($params[$param]);
                }
            }

            if (str_starts_with($key, 'payload.')) {
                $value = $this->getValueByPath($context->payload(), substr($key, 8));
                return $this->stringify($value);
            }

            return $matches[0];
        }, $value) ?? $value;
    }
}
