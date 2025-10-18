<?php

declare(strict_types=1);

namespace AavionDB\Core\Security;

use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles authentication state and guards REST access.
 */
final class AuthManager
{
    private BrainRepository $brains;

    private LoggerInterface $logger;

    public function __construct(BrainRepository $brains, LoggerInterface $logger)
    {
        $this->brains = $brains;
        $this->logger = $logger;
    }

    /**
     * Validates REST access against the persisted auth state.
     *
     * @return array{allowed: bool, status_code: int, payload: array<string, mixed>}
     */
    public function guardRestAccess(?string $token, string $action): array
    {
        try {
            $state = $this->brains->systemAuthState();
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to load authentication state.', ['exception' => $exception]);

            return [
                'allowed' => false,
                'status_code' => 500,
                'payload' => $this->errorPayload(
                    $action,
                    'Authentication subsystem unavailable.',
                    ['reason' => 'auth_unavailable']
                ),
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
            ];
        }

        $token = $token !== null ? \trim($token) : '';
        if ($token === '') {
            return [
                'allowed' => false,
                'status_code' => 401,
                'payload' => $this->errorPayload(
                    $action,
                    'Missing API token.',
                    ['reason' => 'token_missing']
                ),
            ];
        }

        $bootstrapKey = isset($auth['bootstrap_key']) && \is_string($auth['bootstrap_key'])
            ? $auth['bootstrap_key']
            : 'admin';

        if (\hash_equals($bootstrapKey, $token)) {
            $this->logger->notice('REST bootstrap token usage blocked.', ['action' => $action]);

            return [
                'allowed' => false,
                'status_code' => 403,
                'payload' => $this->errorPayload(
                    $action,
                    'Bootstrap token cannot be used for REST access.',
                    ['reason' => 'bootstrap_forbidden']
                ),
            ];
        }

        $lookup = $this->locateToken($auth['keys'] ?? [], $token);
        if ($lookup === null) {
            $this->logger->warning('REST access denied for unknown token.', ['action' => $action]);

            return [
                'allowed' => false,
                'status_code' => 401,
                'payload' => $this->errorPayload(
                    $action,
                    'Invalid or unknown API token.',
                    ['reason' => 'token_invalid']
                ),
            ];
        }

        if (\strtolower($lookup['status'] ?? 'active') !== 'active') {
            $this->logger->notice('REST access attempted with inactive token.', [
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
            ];
        }

        try {
            $this->brains->touchAuthKey($lookup['hash'], $lookup['token_preview'] ?? $this->preview($token));
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to record token usage.', [
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
                ],
            ],
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
}
