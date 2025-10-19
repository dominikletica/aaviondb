<?php

declare(strict_types=1);

namespace AavionDB\Modules\Security;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Security\SecurityManager;
use function array_map;
use function explode;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

final class SecurityAgent
{
    private ModuleContext $context;

    private SecurityManager $security;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->security = $context->security();
        $this->security->ensureDefaults();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('security', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $parameters = [];

            $subcommand = 'config';
            if ($tokens !== []) {
                $first = trim((string) $tokens[0]);
                if ($first !== '' && !$this->looksLikeAssignment($first)) {
                    $subcommand = strtolower($first);
                    array_shift($tokens);
                }
            }

            $parameters['subcommand'] = $subcommand;

            if ($tokens !== [] && $subcommand === 'lockdown') {
                $candidate = trim((string) $tokens[0]);
                if ($candidate !== '' && !$this->looksLikeAssignment($candidate)) {
                    $parameters['duration'] = $candidate;
                    array_shift($tokens);
                }
            }

            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }

                $stripped = $token;
                if (str_starts_with($stripped, '--')) {
                    $stripped = substr($stripped, 2);
                }

                if (!str_contains($stripped, '=')) {
                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $stripped, 2));
                if ($key === '') {
                    continue;
                }

                if ($key === 'duration' || $key === 'seconds') {
                    $parameters['duration'] = $value;
                }
            }

            $context->setAction('security');
            $context->mergeParameters($parameters);
            $context->setTokens([]);
        }, 10);
    }

    private function registerCommand(): void
    {
        $this->context->commands()->register('security', function (array $parameters): CommandResponse {
            return $this->handleSecurityCommand($parameters);
        }, [
            'description' => 'Manage security settings, rate limits, and lockdown state.',
            'group' => 'security',
            'usage' => 'security [config|enable|disable|lockdown [seconds]|purge]',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function handleSecurityCommand(array $parameters): CommandResponse
    {
        $subcommand = strtolower((string) ($parameters['subcommand'] ?? 'config'));

        return match ($subcommand) {
            '', 'config', 'status' => $this->configCommand(),
            'enable' => $this->toggleCommand(true),
            'disable' => $this->toggleCommand(false),
            'lockdown' => $this->lockdownCommand($parameters),
            'purge' => $this->purgeCommand(),
            default => CommandResponse::error('security', sprintf('Unknown security subcommand "%s".', $subcommand)),
        };
    }

    private function configCommand(): CommandResponse
    {
        $status = $this->security->status();
        $config = $status['config'] ?? [];
        $enabled = (bool) ($config['active'] ?? false);
        $lockdownActive = (bool) ($status['lockdown_active'] ?? false);
        $lockdownNote = '';

        if ($lockdownActive) {
            $until = $status['lockdown_until'] ?? null;
            $lockdownNote = $until !== null
                ? sprintf(' Lockdown active until %s.', $until)
                : ' Lockdown active.';
        }

        $message = $enabled ? 'Security subsystem is enabled.' : 'Security subsystem is disabled.';
        $message .= $lockdownNote;
        $message .= ' Update limits via `set security.<key> <value> --system=1`.';

        return CommandResponse::success('security', [
            'enabled' => $enabled,
            'config' => $config,
            'lockdown_active' => $lockdownActive,
            'lockdown_until' => $status['lockdown_until'] ?? null,
            'lockdown_reason' => $status['lockdown_reason'] ?? null,
        ], $message);
    }

    private function toggleCommand(bool $enabled): CommandResponse
    {
        $this->security->setEnabled($enabled);

        return CommandResponse::success('security', [
            'enabled' => $enabled,
        ], $enabled ? 'Security subsystem enabled.' : 'Security subsystem disabled and caches purged.');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function lockdownCommand(array $parameters): CommandResponse
    {
        $value = $parameters['duration'] ?? null;
        $duration = null;

        if ($value !== null) {
            if (!is_string($value) && !is_numeric($value)) {
                return CommandResponse::error('security', 'Lockdown duration must be a numeric value (seconds).');
            }

            $duration = (int) $value;
            if ($duration <= 0) {
                return CommandResponse::error('security', 'Lockdown duration must be greater than zero seconds.');
            }
        }

        $result = $this->security->lockdown($duration, 'manual');

        $message = sprintf('Security lockdown active for %d seconds (until %s).', $result['retry_after'], $result['locked_until']);

        return CommandResponse::success('security', [
            'lockdown' => true,
            'retry_after' => $result['retry_after'],
            'locked_until' => $result['locked_until'],
        ], $message);
    }

    private function purgeCommand(): CommandResponse
    {
        $removed = $this->security->purge();

        return CommandResponse::success('security', [
            'purged' => $removed,
        ], sprintf('Purged %d cached security artefacts.', $removed));
    }

    private function looksLikeAssignment(string $token): bool
    {
        $trimmed = trim($token);

        return $trimmed !== '' && (str_contains($trimmed, '=') || str_starts_with($trimmed, '--'));
    }
}
