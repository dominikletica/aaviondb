<?php

declare(strict_types=1);

namespace AavionDB\Modules\Api;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_map;
use function array_shift;
use function array_unshift;
use function explode;
use function is_string;
use function strtolower;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

final class ApiAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerServeCommand();
        $this->registerStopCommand();
        $this->registerStatusCommand();
        $this->registerResetCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('api', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('api status');
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'serve':
                    $context->setAction('api serve');
                    break;
                case 'stop':
                    $context->setAction('api stop');
                    break;
                case 'status':
                    $context->setAction('api status');
                    break;
                case 'reset':
                    $context->setAction('api reset');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('api status');
                    break;
            }

            $this->injectParameters($context, $tokens);
        }, 10);
    }

    private function injectParameters(ParserContext $context, array $tokens): void
    {
        $parameters = [];

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

    private function registerServeCommand(): void
    {
        $this->context->commands()->register('api serve', function (array $parameters): CommandResponse {
            return $this->apiServeCommand($parameters);
        }, [
            'description' => 'Enable the REST API.',
            'group' => 'api',
            'usage' => 'api serve [reason=text]',
        ]);
    }

    private function registerStopCommand(): void
    {
        $this->context->commands()->register('api stop', function (array $parameters): CommandResponse {
            return $this->apiStopCommand($parameters);
        }, [
            'description' => 'Disable the REST API.',
            'group' => 'api',
            'usage' => 'api stop [reason=text]',
        ]);
    }

    private function registerStatusCommand(): void
    {
        $this->context->commands()->register('api status', function (array $parameters): CommandResponse {
            return $this->apiStatusCommand();
        }, [
            'description' => 'Display REST API status.',
            'group' => 'api',
            'usage' => 'api status',
        ]);
    }

    private function registerResetCommand(): void
    {
        $this->context->commands()->register('api reset', function (array $parameters): CommandResponse {
            return $this->apiResetCommand();
        }, [
            'description' => 'Disable REST and revoke API tokens.',
            'group' => 'api',
            'usage' => 'api reset',
        ]);
    }

    private function apiServeCommand(array $parameters): CommandResponse
    {
        try {
            $state = $this->brains->systemAuthState();
        } catch (Throwable $exception) {
            $this->logger->error('Failed to read authentication state before enabling REST.', ['exception' => $exception]);

            return CommandResponse::error('api serve', 'Unable to load authentication state.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        $auth = $state['auth'] ?? [];
        $activeKeys = (int) ($auth['active_keys'] ?? 0);

        if ($activeKeys === 0) {
            return CommandResponse::error('api serve', 'No active API tokens detected. Generate one via "auth grant" first.', [
                'auth' => [
                    'active_keys' => 0,
                    'bootstrap_active' => $auth['bootstrap_effective'] ?? true,
                ],
            ]);
        }

        $reason = $this->normaliseReason($parameters['reason'] ?? null);

        try {
            $changed = $this->brains->setApiEnabled(true, [
                'actor' => 'api serve',
                'reason' => $reason,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to enable REST API.', ['exception' => $exception]);

            return CommandResponse::error('api serve', 'Unable to enable REST API.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        try {
            $state = $this->brains->systemAuthState();
        } catch (Throwable $exception) {
            $this->logger->warning('Enabled REST API but failed to reload state.', ['exception' => $exception]);
        }

        $message = $changed ? 'REST API enabled.' : 'REST API already enabled.';

        if ($changed) {
            $this->logger->notice('REST API enabled via ApiAgent.', ['reason' => $reason]);
        }

        return CommandResponse::success('api serve', $this->summarizeState($state ?? null, $changed), $message);
    }

    private function apiStopCommand(array $parameters): CommandResponse
    {
        $reason = $this->normaliseReason($parameters['reason'] ?? null);

        try {
            $changed = $this->brains->setApiEnabled(false, [
                'actor' => 'api stop',
                'reason' => $reason,
            ]);
            $state = $this->brains->systemAuthState();
        } catch (Throwable $exception) {
            $this->logger->error('Failed to disable REST API.', ['exception' => $exception]);

            return CommandResponse::error('api stop', 'Unable to disable REST API.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        $message = $changed ? 'REST API disabled.' : 'REST API already disabled.';

        if ($changed) {
            $this->logger->notice('REST API disabled via ApiAgent.', ['reason' => $reason]);
        }

        return CommandResponse::success('api stop', $this->summarizeState($state, $changed), $message);
    }

    private function apiStatusCommand(): CommandResponse
    {
        try {
            $state = $this->brains->systemAuthState();
        } catch (Throwable $exception) {
            $this->logger->error('Failed to read REST API status.', ['exception' => $exception]);

            return CommandResponse::error('api status', 'Unable to load REST API status.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        return CommandResponse::success('api status', $this->summarizeState($state, null), 'REST API status snapshot.');
    }

    private function apiResetCommand(): CommandResponse
    {
        try {
            $result = $this->brains->resetAuthTokens();
        } catch (Throwable $exception) {
            $this->logger->error('Failed to reset REST API tokens.', ['exception' => $exception]);

            return CommandResponse::error('api reset', 'Unable to reset REST API tokens.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        $this->logger->notice('REST API reset executed; tokens revoked.');

        return CommandResponse::success('api reset', $result, 'REST API disabled and all tokens revoked.');
    }

    private function summarizeState(?array $state, ?bool $changed): array
    {
        $auth = $state['auth'] ?? [];
        $api = $state['api'] ?? [];

        $summary = [
            'enabled' => (bool) ($api['enabled'] ?? false),
            'active_tokens' => (int) ($auth['active_keys'] ?? 0),
            'bootstrap_active' => (bool) ($auth['bootstrap_effective'] ?? false),
            'last_enabled_at' => $api['last_enabled_at'] ?? null,
            'last_disabled_at' => $api['last_disabled_at'] ?? null,
            'last_request_at' => $api['last_request_at'] ?? null,
            'last_actor' => $api['last_actor'] ?? null,
        ];

        if ($changed !== null) {
            $summary['changed'] = $changed;
        }

        return $summary;
    }

    private function normaliseReason($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
