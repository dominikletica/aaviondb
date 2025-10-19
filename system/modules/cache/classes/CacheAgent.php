<?php

declare(strict_types=1);

namespace AavionDB\Modules\Cache;

use AavionDB\Core\Cache\CacheManager;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function glob;
use function implode;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function str_contains;
use function strtolower;
use function substr;
use function sprintf;
use function trim;
use function rtrim;

final class CacheAgent
{
    private ModuleContext $context;

    private CacheManager $cache;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->cache = $context->cache();
        $this->cache->ensureDefaults();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('cache', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $parameters = [];
            $subcommand = null;

            if ($tokens !== []) {
                $first = $this->normaliseToken($tokens[0]);
                if ($first !== '' && !$this->looksLikeAssignment($tokens[0])) {
                    $subcommand = strtolower($first);
                    array_shift($tokens);
                }
            }

            if ($subcommand === null) {
                $subcommand = 'status';
            }

            $parameters['subcommand'] = $subcommand;

            if ($tokens !== []) {
                $first = $tokens[0] ?? '';
                if ($subcommand === 'ttl' && !$this->looksLikeAssignment($first)) {
                    $parameters['ttl'] = $this->normaliseToken($first);
                    array_shift($tokens);
                } elseif ($subcommand === 'purge' && !$this->looksLikeAssignment($first)) {
                    $parameters['key'] = $first;
                    array_shift($tokens);
                }
            }

            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }

                $prefixed = $token;
                if (str_starts_with($prefixed, '--')) {
                    $prefixed = substr($prefixed, 2);
                }

                if (str_contains($prefixed, '=')) {
                    [$key, $value] = array_map('trim', explode('=', $prefixed, 2));
                    if ($key === '') {
                        continue;
                    }

                    if ($key === 'ttl') {
                        $parameters['ttl'] = $value;
                        continue;
                    }

                    if ($key === 'tag' || $key === 'tags') {
                        $parameters['tags'] = $value;
                        continue;
                    }

                    if ($key === 'key') {
                        $parameters['key'] = $value;
                        continue;
                    }
                }
            }

            $context->setAction('cache');
            $context->mergeParameters($parameters);
            $context->setTokens([]);
        }, 10);
    }

    private function registerCommand(): void
    {
        $this->context->commands()->register('cache', function (array $parameters): CommandResponse {
            return $this->handleCacheCommand($parameters);
        }, [
            'description' => 'Inspect and configure the cache subsystem.',
            'group' => 'cache',
            'usage' => 'cache [status|enable|disable|ttl <seconds>|purge [key=...] [tag=...]]',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function handleCacheCommand(array $parameters): CommandResponse
    {
        $subcommand = strtolower((string) ($parameters['subcommand'] ?? 'status'));

        return match ($subcommand) {
            '', 'status' => $this->statusCommand(),
            'enable' => $this->toggleCommand(true),
            'disable' => $this->toggleCommand(false),
            'ttl' => $this->ttlCommand($parameters),
            'purge' => $this->purgeCommand($parameters),
            default => CommandResponse::error('cache', sprintf('Unknown cache subcommand "%s".', $subcommand)),
        };
    }

    private function statusCommand(): CommandResponse
    {
        $expired = $this->cache->cleanupExpired();
        $enabled = $this->cache->isEnabled();
        $ttl = $this->cache->ttl();
        $directory = $this->cache->directory();
        $entries = $this->countEntries();

        $message = $enabled
            ? sprintf('Cache is enabled (ttl=%ds, %d entries, %d expired removed).', $ttl, $entries, $expired)
            : sprintf('Cache is disabled (%d expired entries removed).', $expired);

        return CommandResponse::success('cache', [
            'enabled' => $enabled,
            'ttl' => $ttl,
            'directory' => $directory,
            'entries' => $entries,
            'expired_removed' => $expired,
        ], $message);
    }

    private function toggleCommand(bool $enabled): CommandResponse
    {
        $this->cache->setEnabled($enabled);

        return CommandResponse::success('cache', [
            'enabled' => $enabled,
        ], $enabled ? 'Cache enabled.' : 'Cache disabled and flushed.');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function ttlCommand(array $parameters): CommandResponse
    {
        $value = $parameters['ttl'] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            return CommandResponse::error('cache', 'TTL requires a numeric value (seconds).');
        }

        $ttl = (int) $value;
        if ($ttl <= 0) {
            return CommandResponse::error('cache', 'TTL must be greater than zero.');
        }

        $this->cache->setTtl($ttl);

        return CommandResponse::success('cache', [
            'enabled' => $this->cache->isEnabled(),
            'ttl' => $ttl,
        ], sprintf('Cache TTL updated to %d seconds.', $ttl));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function purgeCommand(array $parameters): CommandResponse
    {
        $key = isset($parameters['key']) ? trim((string) $parameters['key']) : '';
        $tags = [];
        if (isset($parameters['tags'])) {
            $tags = $this->splitTags((string) $parameters['tags']);
        } elseif (isset($parameters['tag'])) {
            $tags = $this->splitTags((string) $parameters['tag']);
        }

        if ($key !== '') {
            $this->cache->forget($key);

            return CommandResponse::success('cache', [
                'purged' => 1,
                'key' => $key,
            ], sprintf('Cache entry "%s" removed.', $key));
        }

        $removed = $this->cache->flush($tags === [] ? null : $tags);

        $message = $tags === []
            ? sprintf('Flushed %d cache entries.', $removed)
            : sprintf('Flushed %d cache entries matching tags [%s].', $removed, implode(', ', $tags));

        return CommandResponse::success('cache', [
            'purged' => $removed,
            'tags' => $tags,
        ], $message);
    }

    private function looksLikeAssignment(string $token): bool
    {
        $trimmed = trim($token);

        return $trimmed === '' ? false : str_contains($trimmed, '=') || str_starts_with($trimmed, '--');
    }

    private function normaliseToken(string $token): string
    {
        return strtolower(trim($token));
    }

    /**
     * @return array<int, string>
     */
    private function splitTags(string $value): array
    {
        $parts = explode(',', $value);

        return array_values(array_filter(array_map(static function (string $tag): string {
            return strtolower(trim($tag));
        }, $parts), static fn (string $tag): bool => $tag !== ''));
    }

    private function countEntries(): int
    {
        $directory = $this->cache->directory();
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.cache.json';
        $files = glob($pattern);

        return $files === false ? 0 : count($files);
    }
}
