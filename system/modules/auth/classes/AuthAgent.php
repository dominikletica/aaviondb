<?php

declare(strict_types=1);

namespace AavionDB\Modules\Auth;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

final class AuthAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @var string[]
     */
    private array $allowedScopes = ['ALL', 'RW', 'RO', 'WO'];

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();

        $container = $context->container();
        $this->config = $container->has('config') ? (array) $container->get('config') : [];
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerGrantCommand();
        $this->registerListCommand();
        $this->registerRevokeCommand();
        $this->registerResetCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('auth', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('auth list');
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'grant':
                    $context->setAction('auth grant');
                    break;
                case 'list':
                    $context->setAction('auth list');
                    break;
                case 'revoke':
                    $context->setAction('auth revoke');
                    break;
                case 'reset':
                    $context->setAction('auth reset');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('auth list');
                    break;
            }

            $this->injectParameters($context, $context->action(), $tokens);
        }, 10);
    }

    private function injectParameters(ParserContext $context, string $action, array $tokens): void
    {
        $parameters = [];

        if (in_array($action, ['auth revoke'], true) && $tokens !== []) {
            $parameters['identifier'] = array_shift($tokens);
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            $key = $token;
            $value = true;

            if (strpos($token, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $token, 2));
            }

            if ($key === '') {
                continue;
            }

            $parameters[$key] = $value;
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerGrantCommand(): void
    {
        $this->context->commands()->register('auth grant', function (array $parameters): CommandResponse {
            return $this->authGrantCommand($parameters);
        }, [
            'description' => 'Generate a scoped API token.',
            'group' => 'auth',
            'usage' => 'auth grant [scope=RW] [projects=*] [label=name]',
        ]);
    }

    private function registerListCommand(): void
    {
        $this->context->commands()->register('auth list', function (array $parameters): CommandResponse {
            return $this->authListCommand();
        }, [
            'description' => 'List registered API tokens.',
            'group' => 'auth',
            'usage' => 'auth list',
        ]);
    }

    private function registerRevokeCommand(): void
    {
        $this->context->commands()->register('auth revoke', function (array $parameters): CommandResponse {
            return $this->authRevokeCommand($parameters);
        }, [
            'description' => 'Revoke an API token by hash/token.',
            'group' => 'auth',
            'usage' => 'auth revoke <token|hash>',
        ]);
    }

    private function registerResetCommand(): void
    {
        $this->context->commands()->register('auth reset', function (array $parameters): CommandResponse {
            return $this->authResetCommand();
        }, [
            'description' => 'Revoke all API tokens and disable REST API.',
            'group' => 'auth',
            'usage' => 'auth reset',
        ]);
    }

    private function authGrantCommand(array $parameters): CommandResponse
    {
        $scope = strtoupper(trim((string) ($parameters['scope'] ?? 'RW')));
        if (!in_array($scope, $this->allowedScopes, true)) {
            return CommandResponse::error('auth grant', sprintf('Invalid scope "%s". Allowed: %s', $scope, implode(',', $this->allowedScopes)));
        }

        $projectsRaw = isset($parameters['projects']) ? (string) $parameters['projects'] : '*';
        $projects = $this->normaliseProjects($projectsRaw);
        $label = isset($parameters['label']) ? trim((string) $parameters['label']) : null;

        $length = (int) ($this->config['api_key_length'] ?? 16);
        if ($length < 8) {
            $length = 16;
        }

        $token = $this->generateToken($length);

        try {
            $metadata = [
                'created_by' => 'auth grant',
                'label' => $label,
                'scope' => $scope,
                'projects' => $projects,
            ];

            $entry = $this->brains->registerAuthToken($token, $metadata);

            $payload = [
                'token' => $entry['token'],
                'hash' => $entry['hash'],
                'scope' => $entry['meta']['scope'] ?? $scope,
                'projects' => $entry['meta']['projects'] ?? $projects,
                'created_at' => $entry['created_at'] ?? null,
            ];

            if ($entry['token_preview'] ?? null) {
                $payload['token_preview'] = $entry['token_preview'];
            }

            return CommandResponse::success('auth grant', $payload, 'API token generated.');
        } catch (Throwable $exception) {
            $this->log(LogLevel::ERROR, 'Failed to grant auth token', [
                'scope' => $scope,
                'projects' => $projects,
                'exception' => $exception,
            ]);

            return CommandResponse::error('auth grant', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function authListCommand(): CommandResponse
    {
        try {
            $tokens = $this->brains->listAuthTokens(true);

            $items = array_map(function ($entry) {
                if (!is_array($entry)) {
                    return $entry;
                }

                return [
                    'hash' => $entry['hash'] ?? null,
                    'status' => $entry['status'] ?? null,
                    'label' => $entry['label'] ?? null,
                    'created_at' => $entry['created_at'] ?? null,
                    'last_used_at' => $entry['last_used_at'] ?? null,
                    'token_preview' => $entry['token_preview'] ?? null,
                    'scope' => $entry['meta']['scope'] ?? null,
                    'projects' => $entry['meta']['projects'] ?? null,
                ];
            }, $tokens);

            return CommandResponse::success('auth list', [
                'count' => count($items),
                'items' => $items,
            ], 'Registered API tokens');
        } catch (Throwable $exception) {
            $this->log(LogLevel::ERROR, 'Failed to list auth tokens', ['exception' => $exception]);

            return CommandResponse::error('auth list', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function authRevokeCommand(array $parameters): CommandResponse
    {
        $identifier = isset($parameters['identifier']) ? trim((string) $parameters['identifier']) : '';
        if ($identifier === '') {
            return CommandResponse::error('auth revoke', 'Token or hash identifier is required.');
        }

        try {
            $revoked = $this->brains->revokeAuthToken($identifier, [
                'revoked_by' => 'auth revoke',
                'reason' => $parameters['reason'] ?? null,
            ]);

            if (!$revoked) {
                return CommandResponse::error('auth revoke', 'Token not found or already revoked.');
            }

            return CommandResponse::success('auth revoke', [
                'identifier' => $identifier,
            ], 'Token revoked.');
        } catch (Throwable $exception) {
            $this->log(LogLevel::ERROR, 'Failed to revoke token', [
                'identifier' => $identifier,
                'exception' => $exception,
            ]);

            return CommandResponse::error('auth revoke', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function authResetCommand(): CommandResponse
    {
        try {
            $result = $this->brains->resetAuthTokens();

            return CommandResponse::success('auth reset', $result, 'All tokens revoked; REST API disabled.');
        } catch (Throwable $exception) {
            $this->log(LogLevel::ERROR, 'Failed to reset auth tokens', ['exception' => $exception]);

            return CommandResponse::error('auth reset', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function generateToken(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $alphabetLength = strlen($alphabet);
        $token = '';

        while (strlen($token) < $length) {
            $bytes = random_bytes($length);
            for ($i = 0; $i < strlen($bytes) && strlen($token) < $length; ++$i) {
                $token .= $alphabet[ord($bytes[$i]) % $alphabetLength];
            }
        }

        return $token;
    }

    private function normaliseProjects(string $projects): array
    {
        $projects = trim($projects);
        if ($projects === '' || $projects === '*') {
            return ['*'];
        }

        $list = array_map('trim', explode(',', $projects));
        $filtered = [];
        foreach ($list as $item) {
            if ($item === '') {
                continue;
            }
            $filtered[] = strtolower($item);
        }

        return $filtered === [] ? ['*'] : $filtered;
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
