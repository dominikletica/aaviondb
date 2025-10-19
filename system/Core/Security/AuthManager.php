<?php

declare(strict_types=1);

namespace AavionDB\Core\Security;

use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Handles authentication state and guards REST access.
 */
final class AuthManager
{
    private BrainRepository $brains;

    private LoggerInterface $logger;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    private string $adminSecret;

    public function __construct(BrainRepository $brains, LoggerInterface $logger, array $config = [])
    {
        $this->brains = $brains;
        $this->logger = $logger;
        $this->config = $config;
        $this->adminSecret = $this->normaliseAdminSecret($config['admin_secret'] ?? '');
    }

    /**
     * Validates REST access against the persisted auth state.
     *
     * @return array{allowed: bool, status_code: int, payload: array<string, mixed>}
     */
    public function guardRestAccess(?string $token, string $action): array
    {
        $token = $token !== null ? \trim($token) : '';

        if ($this->allowsAdminSecret($token)) {
            $this->log(LogLevel::NOTICE, 'REST access granted via admin secret.', ['action' => $action]);

            $scope = $this->defaultScope();

            return [
                'allowed' => true,
                'status_code' => 200,
                'payload' => [
                    'status' => 'ok',
                    'action' => $action,
                    'message' => 'Access granted (admin secret).',
                    'data' => null,
                    'meta' => [
                        'mode' => 'admin_secret',
                    ],
                ],
                'scope' => $scope,
            ];
        }

        try {
            $state = $this->brains->systemAuthState();
        } catch (\Throwable $exception) {
            $this->log(LogLevel::ERROR, 'Unable to load authentication state.', ['exception' => $exception]);

            return [
                'allowed' => false,
                'status_code' => 500,
                'payload' => $this->errorPayload(
                    $action,
                    'Authentication subsystem unavailable.',
                    ['reason' => 'auth_unavailable']
                ),
                'scope' => null,
            ];
        }

        $auth = $state['auth'] ?? [];
        $api = $state['api'] ?? [];

        if (($api['enabled'] ?? false) !== true) {
            return [
                'allowed' => false,
                'status_code' => 503,
                'payload' => $this->errorPayload(
                    $action,
                    'REST API is disabled. Use CLI command "api serve" to enable it.',
                    [
                        'reason' => 'api_disabled',
                        'bootstrap_active' => $auth['bootstrap_effective'] ?? true,
                    ]
                ),
                'scope' => null,
            ];
        }

        if ($token === '') {
            return [
                'allowed' => false,
                'status_code' => 401,
                'payload' => $this->errorPayload(
                    $action,
                    'Missing API token.',
                    ['reason' => 'token_missing']
                ),
                'scope' => null,
            ];
        }

        $bootstrapKey = isset($auth['bootstrap_key']) && \is_string($auth['bootstrap_key'])
            ? $auth['bootstrap_key']
            : 'admin';

        if (\hash_equals($bootstrapKey, $token)) {
            $this->log(LogLevel::NOTICE, 'REST bootstrap token usage blocked.', ['action' => $action]);

            return [
                'allowed' => false,
                'status_code' => 403,
                'payload' => $this->errorPayload(
                    $action,
                    'Bootstrap token cannot be used for REST access.',
                    ['reason' => 'bootstrap_forbidden']
                ),
                'scope' => null,
            ];
        }

        $lookup = $this->locateToken($auth['keys'] ?? [], $token);
        if ($lookup === null) {
            $this->log(LogLevel::WARNING, 'REST access denied for unknown token.', ['action' => $action]);

            return [
                'allowed' => false,
                'status_code' => 401,
                'payload' => $this->errorPayload(
                    $action,
                    'Invalid or unknown API token.',
                    ['reason' => 'token_invalid']
                ),
                'scope' => null,
            ];
        }

        if (\strtolower($lookup['status'] ?? 'active') !== 'active') {
            $this->log(LogLevel::NOTICE, 'REST access attempted with inactive token.', [
                'action' => $action,
                'token' => $lookup['hash'] ?? null,
                'status' => $lookup['status'] ?? null,
            ]);

            return [
                'allowed' => false,
                'status_code' => 403,
                'payload' => $this->errorPayload(
                    $action,
                    'API token is not active.',
                    ['reason' => 'token_inactive']
                ),
                'scope' => null,
            ];
        }

        $scope = $this->scopeFromToken($lookup);

        try {
            $this->brains->touchAuthKey($lookup['hash'], $lookup['token_preview'] ?? $this->preview($token));
        } catch (\Throwable $exception) {
            $this->log(LogLevel::WARNING, 'Failed to record token usage.', [
                'action' => $action,
                'token' => $lookup['hash'] ?? null,
                'exception' => $exception,
            ]);
        }

        return [
            'allowed' => true,
            'status_code' => 200,
            'payload' => [
                'status' => 'ok',
                'action' => $action,
                'message' => 'Access granted.',
                'data' => null,
                'meta' => [
                    'token_hash' => $lookup['hash'] ?? null,
                    'scope' => $scope['mode'],
                    'projects' => $scope['projects'],
                ],
            ],
            'scope' => $scope,
        ];
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<string, mixed>|null
     */
    private function locateToken($keys, string $token): ?array
    {
        if (!\is_array($keys)) {
            return null;
        }

        $hash = \hash('sha256', $token);

        foreach ($keys as $identifier => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $entryHash = isset($entry['hash']) && \is_string($entry['hash'])
                ? \strtolower($entry['hash'])
                : (\is_string($identifier) ? \strtolower($identifier) : null);

            if ($entryHash !== $hash) {
                continue;
            }

            $entry['hash'] = $hash;

            if (!isset($entry['token_preview']) || !\is_string($entry['token_preview'])) {
                $entry['token_preview'] = $this->preview($token);
            }

            return $entry;
        }

        return null;
    }

    private function preview(string $token): string
    {
        $visible = \substr($token, 0, 4);

        return \sprintf('%s...', $visible);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function errorPayload(string $action, string $message, array $meta = []): array
    {
        return [
            'status' => 'error',
            'action' => $action,
            'message' => $message,
            'data' => null,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{mode: string, projects: array<int, string>}
     */
    private function defaultScope(): array
    {
        return [
            'mode' => 'ALL',
            'projects' => ['*'],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{mode: string, projects: array<int, string>}
     */
    private function scopeFromToken(array $entry): array
    {
        $meta = isset($entry['meta']) && \is_array($entry['meta']) ? $entry['meta'] : [];
        $mode = \strtoupper((string) ($meta['scope'] ?? 'ALL'));
        $projects = $meta['projects'] ?? ['*'];

        if (!\is_array($projects)) {
            $projects = \array_map('trim', \explode(',', (string) $projects));
        }

        $projects = \array_values(\array_filter(\array_map(static function ($value) {
            if ($value === null) {
                return null;
            }

            $value = \strtolower((string) $value);
            return $value === '' ? null : $value;
        }, $projects), static fn ($value) => $value !== null));

        if ($projects === [] || \in_array('*', $projects, true)) {
            $projects = ['*'];
        }

        return [
            'mode' => $mode,
            'projects' => $projects,
        ];
    }

    private function normaliseAdminSecret(string $secret): string
    {
        $secret = \trim($secret);

        if ($secret === '') {
            return '';
        }

        if (!\str_starts_with($secret, '_') || \strlen($secret) < 8) {
            $this->log(LogLevel::WARNING, 'Configured admin secret is invalid (must start with "_" and be at least 8 characters).');

            return '';
        }

        return $secret;
    }

    private function allowsAdminSecret(string $token): bool
    {
        return $this->adminSecret !== '' && $token !== '' && \hash_equals($this->adminSecret, $token);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['category'] = 'AUTH';
        $this->logger->log($level, $message, $context);
    }
}
