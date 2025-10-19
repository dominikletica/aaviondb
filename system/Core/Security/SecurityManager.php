<?php

declare(strict_types=1);

namespace AavionDB\Core\Security;

use AavionDB\Core\Cache\CacheManager;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use function array_key_exists;
use function array_merge;
use function hash;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function sprintf;
use function strtolower;
use function time;
use function trim;
use const DATE_ATOM;

/**
 * Provides runtime security features such as rate limiting and lockdown management.
 */
final class SecurityManager
{
    private const DEFAULTS = [
        'security.active' => true,
        'security.rate_limit' => 60,
        'security.global_limit' => 120,
        'security.block_duration' => 300,
        'security.ddos_lockdown' => 300,
        'security.failed_limit' => 3,
        'security.failed_block' => 300,
    ];

    private BrainRepository $brains;

    private CacheManager $cache;

    private LoggerInterface $logger;

    private bool $defaultsEnsured = false;

    public function __construct(BrainRepository $brains, CacheManager $cache, LoggerInterface $logger)
    {
        $this->brains = $brains;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Ensures default configuration keys exist.
     */
    public function ensureDefaults(): void
    {
        if ($this->defaultsEnsured) {
            return;
        }

        $this->brains->ensureSystemBrain();
        $config = $this->brains->listConfig(true);

        foreach (self::DEFAULTS as $key => $value) {
            if (!array_key_exists($key, $config)) {
                $this->brains->setConfigValue($key, $value, true);
            }
        }

        $this->defaultsEnsured = true;
    }

    /**
     * Returns whether the security subsystem is active.
     */
    public function isEnabled(): bool
    {
        $this->ensureDefaults();

        $value = $this->brains->getConfigValue('security.active', true, true);

        return $this->toBool($value);
    }

    /**
     * Enables or disables the security subsystem.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->brains->setConfigValue('security.active', $enabled, true);

        if (!$enabled) {
            $this->purge();
        }
    }

    /**
     * Returns the normalised security configuration values.
     *
     * @return array<string, int|bool>
     */
    public function config(): array
    {
        $this->ensureDefaults();

        return [
            'active' => $this->isEnabled(),
            'rate_limit' => $this->intValue($this->brains->getConfigValue('security.rate_limit', 60, true), 60, 1),
            'global_limit' => $this->intValue($this->brains->getConfigValue('security.global_limit', 120, true), 120, 0),
            'block_duration' => $this->intValue($this->brains->getConfigValue('security.block_duration', 300, true), 300, 1),
            'ddos_lockdown' => $this->intValue($this->brains->getConfigValue('security.ddos_lockdown', 300, true), 300, 1),
            'failed_limit' => $this->intValue($this->brains->getConfigValue('security.failed_limit', 3, true), 3, 1),
            'failed_block' => $this->intValue($this->brains->getConfigValue('security.failed_block', 300, true), 300, 1),
        ];
    }

    /**
     * Performs preflight checks before a request is processed.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    public function preflight(string $clientKey, array $context = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $client = $this->normaliseClient($clientKey);
        $now = $this->now();
        $ctx = $this->augmentContext($context, $client);

        $lockdown = $this->cache->get($this->lockdownKey(), null, true);
        if (is_array($lockdown) && isset($lockdown['locked_until']) && is_int($lockdown['locked_until']) && $lockdown['locked_until'] > $now) {
            $retryAfter = max(1, $lockdown['locked_until'] - $now);

            return $this->errorResponse(
                'security.lockdown',
                'Temporary security lockdown is active. Please retry later.',
                503,
                [
                    'reason' => 'lockdown',
                    'retry_after' => $retryAfter,
                    'locked_until' => $this->formatTimestamp($lockdown['locked_until']),
                ],
                $ctx
            );
        }

        $block = $this->cache->get($this->blockKey($client['hash']), null, true);
        if (is_array($block) && isset($block['blocked_until']) && is_int($block['blocked_until']) && $block['blocked_until'] > $now) {
            $retryAfter = max(1, $block['blocked_until'] - $now);

            return $this->errorResponse(
                'security.blocked',
                'Client is temporarily blocked due to rate limits.',
                429,
                [
                    'reason' => $block['reason'] ?? 'rate_limit',
                    'retry_after' => $retryAfter,
                    'blocked_until' => $this->formatTimestamp($block['blocked_until']),
                ],
                $ctx
            );
        }

        return null;
    }

    /**
     * Records a request attempt and enforces rate limits.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    public function registerAttempt(string $clientKey, array $context = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $config = $this->config();
        $client = $this->normaliseClient($clientKey);
        $ctx = $this->augmentContext($context, $client);

        $clientCounter = $this->incrementWindowCounter(
            $this->clientCounterKey($client['hash']),
            (int) $config['rate_limit'],
            ['security:client', 'security:client:' . $client['hash']],
            $config
        );

        if ($clientCounter['exceeded']) {
            $block = $this->blockClient($client, 'rate_limit', (int) $config['block_duration'], $ctx);

            $this->logger->warning('Client rate limit exceeded.', [
                'client' => $client['normalized'],
                'retry_after' => $block['retry_after'],
                'action' => $ctx['action'] ?? null,
            ]);

            return $this->errorResponse(
                'security.rate_limit',
                'Rate limit exceeded. Please retry later.',
                429,
                [
                    'reason' => 'rate_limit',
                    'retry_after' => $block['retry_after'],
                    'blocked_until' => $block['blocked_until'],
                ],
                $ctx
            );
        }

        if ((int) $config['global_limit'] > 0) {
            $global = $this->incrementWindowCounter(
                $this->globalCounterKey(),
                (int) $config['global_limit'],
                ['security:global'],
                $config
            );

            if ($global['exceeded']) {
                $lockdown = $this->startLockdown((int) $config['ddos_lockdown'], 'global_limit', $ctx);

                return $this->errorResponse(
                    'security.lockdown',
                    'Global request limit exceeded. Temporary lockdown activated.',
                    503,
                    [
                        'reason' => 'lockdown',
                        'retry_after' => $lockdown['retry_after'],
                        'locked_until' => $lockdown['locked_until'],
                    ],
                    $ctx
                );
            }
        }

        return null;
    }

    /**
     * Records a failed authentication attempt and triggers blocking when thresholds are exceeded.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function registerFailure(string $clientKey, array $context = []): array
    {
        if (!$this->isEnabled()) {
            return [
                'blocked' => false,
                'retry_after' => null,
                'reason' => null,
            ];
        }

        $config = $this->config();
        $client = $this->normaliseClient($clientKey);
        $ctx = $this->augmentContext($context, $client);

        $failure = $this->incrementWindowCounter(
            $this->failureCounterKey($client['hash']),
            (int) $config['failed_limit'],
            ['security:failure', 'security:client:' . $client['hash']],
            $config
        );

        if ($failure['exceeded']) {
            $block = $this->blockClient($client, 'auth_failure', (int) $config['failed_block'], $ctx);

            $this->logger->notice('Client blocked after repeated authentication failures.', [
                'client' => $client['normalized'],
                'retry_after' => $block['retry_after'],
                'action' => $ctx['action'] ?? null,
            ]);

            return [
                'blocked' => true,
                'retry_after' => $block['retry_after'],
                'reason' => 'auth_failure',
            ];
        }

        return [
            'blocked' => false,
            'retry_after' => null,
            'reason' => null,
        ];
    }

    /**
     * Clears failure counters for successful requests.
     *
     * @param array<string, mixed> $context
     */
    public function registerSuccess(string $clientKey, array $context = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $client = $this->normaliseClient($clientKey);

        $this->cache->forget($this->failureCounterKey($client['hash']));

        if (($context['mode'] ?? null) === 'admin_secret') {
            $this->cache->forget($this->blockKey($client['hash']));
        }
    }

    /**
     * Activates a lockdown for the provided duration (seconds).
     *
     * @return array{retry_after: int, locked_until: string}
     */
    public function lockdown(?int $duration = null, string $reason = 'manual'): array
    {
        $config = $this->config();
        $duration = $duration ?? (int) $config['ddos_lockdown'];

        return $this->startLockdown($duration, $reason, ['action' => 'security.lockdown']);
    }

    /**
     * Purges cached security artefacts.
     */
    public function purge(): int
    {
        return $this->cache->flush(['security']);
    }

    /**
     * Returns runtime status information.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $config = $this->config();
        $now = $this->now();
        $lockdown = $this->cache->get($this->lockdownKey(), null, true);

        $lockdownActive = false;
        $lockdownUntil = null;
        $lockdownReason = null;

        if (is_array($lockdown) && isset($lockdown['locked_until']) && is_int($lockdown['locked_until']) && $lockdown['locked_until'] > $now) {
            $lockdownActive = true;
            $lockdownUntil = $this->formatTimestamp($lockdown['locked_until']);
            $lockdownReason = is_string($lockdown['reason'] ?? null) ? $lockdown['reason'] : null;
        }

        return [
            'config' => $config,
            'lockdown_active' => $lockdownActive,
            'lockdown_until' => $lockdownUntil,
            'lockdown_reason' => $lockdownReason,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function augmentContext(array $context, array $client): array
    {
        $context['client'] = $client['normalized'];

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function errorResponse(
        string $event,
        string $message,
        int $statusCode,
        array $meta,
        array $context
    ): array {
        $payload = [
            'status' => 'error',
            'action' => $context['action'] ?? 'security',
            'message' => $message,
            'meta' => array_merge($meta, [
                'client' => $context['client'] ?? null,
            ]),
        ];

        $headers = [];
        if (isset($meta['retry_after']) && is_int($meta['retry_after'])) {
            $headers['Retry-After'] = (string) max(1, $meta['retry_after']);
        }

        return [
            'status_code' => $statusCode,
            'payload' => $payload,
            'headers' => $headers,
            'event' => $event,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{retry_after: int, locked_until: string}
     */
    private function startLockdown(int $duration, string $reason, array $context = []): array
    {
        $duration = max(1, $duration);
        $now = $this->now();
        $until = $now + $duration;

        $payload = [
            'reason' => $reason,
            'created_at' => $now,
            'locked_until' => $until,
        ];

        $this->cache->put(
            $this->lockdownKey(),
            $payload,
            $duration + 60,
            ['security', 'security:lockdown'],
            true
        );

        $this->logger->error('Security lockdown activated.', [
            'reason' => $reason,
            'locked_until' => $this->formatTimestamp($until),
            'action' => $context['action'] ?? null,
        ]);

        return [
            'retry_after' => max(1, $until - $now),
            'locked_until' => $this->formatTimestamp($until),
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{retry_after: int, blocked_until: string}
     */
    private function blockClient(array $client, string $reason, int $duration, array $context = []): array
    {
        $duration = max(1, $duration);
        $now = $this->now();
        $until = $now + $duration;

        $payload = [
            'client' => $client['normalized'],
            'reason' => $reason,
            'blocked_until' => $until,
            'created_at' => $now,
            'action' => $context['action'] ?? null,
        ];

        $this->cache->put(
            $this->blockKey($client['hash']),
            $payload,
            $duration + 60,
            ['security', 'security:block', 'security:client:' . $client['hash']],
            true
        );

        return [
            'retry_after' => max(1, $until - $now),
            'blocked_until' => $this->formatTimestamp($until),
        ];
    }

    /**
     * @return array{count: int, exceeded: bool}
     */
    private function incrementWindowCounter(string $key, int $limit, array $tags, array $config): array
    {
        $now = $this->now();
        $window = (int) \floor($now / 60);
        $entry = $this->cache->get($key, null, true);

        if (!is_array($entry) || !isset($entry['window']) || (int) $entry['window'] !== $window) {
            $entry = [
                'window' => $window,
                'count' => 0,
            ];
        }

        $entry['count'] = ((int) ($entry['count'] ?? 0)) + 1;
        $entry['updated_at'] = $now;

        $ttl = $this->counterTtl($config);

        $this->cache->put(
            $key,
            $entry,
            $ttl,
            array_merge(['security', 'security:counter'], $tags),
            true
        );

        return [
            'count' => (int) $entry['count'],
            'exceeded' => $limit > 0 && (int) $entry['count'] > $limit,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function counterTtl(array $config): int
    {
        $max = max(
            120,
            (int) $config['block_duration'] + 60,
            (int) $config['ddos_lockdown'] + 60,
            (int) $config['failed_block'] + 60
        );

        return $max;
    }

    /**
     * @return array{key: string, normalized: string, hash: string}
     */
    private function normaliseClient(string $clientKey): array
    {
        $trimmed = strtolower(trim($clientKey));
        if ($trimmed === '') {
            $trimmed = 'anonymous';
        }

        return [
            'key' => $clientKey,
            'normalized' => $trimmed,
            'hash' => hash('sha256', $trimmed),
        ];
    }

    private function securityPrefix(): string
    {
        return 'security';
    }

    private function lockdownKey(): string
    {
        return $this->securityPrefix() . ':lockdown';
    }

    private function globalCounterKey(): string
    {
        return $this->securityPrefix() . ':global:requests';
    }

    private function clientCounterKey(string $hash): string
    {
        return sprintf('%s:client:%s:requests', $this->securityPrefix(), $hash);
    }

    private function failureCounterKey(string $hash): string
    {
        return sprintf('%s:client:%s:failures', $this->securityPrefix(), $hash);
    }

    private function blockKey(string $hash): string
    {
        return sprintf('%s:block:%s', $this->securityPrefix(), $hash);
    }

    private function formatTimestamp(int $timestamp): string
    {
        return \gmdate(DATE_ATOM, $timestamp);
    }

    private function now(): int
    {
        return time();
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return \in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }

    private function intValue(mixed $value, int $default, int $min): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;

            return $int < $min ? $min : $int;
        }

        return $default;
    }
}
