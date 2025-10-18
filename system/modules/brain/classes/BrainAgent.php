<?php

declare(strict_types=1);

namespace AavionDB\Modules\Brain;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function strtolower;
use function trim;
use function str_starts_with;

final class BrainAgent
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
        $this->registerBrainsCommand();
        $this->registerBrainInitCommand();
        $this->registerBrainSwitchCommand();
        $this->registerBrainBackupCommand();
        $this->registerBrainInfoCommand();
        $this->registerBrainValidateCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('brain', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('brains');
                return;
            }

            $sub = strtolower(array_shift($tokens));

            switch ($sub) {
                case 'init':
                    $context->setAction('brain init');
                    $this->injectParameters($context, $tokens, true);
                    return;
                case 'switch':
                    $context->setAction('brain switch');
                    $this->injectParameters($context, $tokens, true);
                    return;
                case 'backup':
                    $context->setAction('brain backup');
                    $this->injectParameters($context, $tokens, false);
                    return;
                case 'info':
                    $context->setAction('brain info');
                    $this->injectParameters($context, $tokens, false);
                    return;
                case 'validate':
                    $context->setAction('brain validate');
                    $this->injectParameters($context, $tokens, false);
                    return;
                case 'list':
                    $context->setAction('brains');
                    $this->injectParameters($context, $tokens, false);
                    return;
                default:
                    // Fallback: treat unknown subcommand as info lookup
                    array_unshift($tokens, $sub);
                    $context->setAction('brain info');
                    $this->injectParameters($context, $tokens, false);
                    return;
            }
        }, 10);
    }

    private function injectParameters(ParserContext $context, array $tokens, bool $expectSlug): void
    {
        $parameters = [];

        if ($expectSlug && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
            }
        } elseif (!$expectSlug && $tokens !== []) {
            $first = $tokens[0];
            if (!str_starts_with($first, '--') && strpos($first, '=') === false) {
                $parameters['slug'] = array_shift($tokens);
            }
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $key = $token;
            $value = true;

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            if (strpos($token, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $token, 2));
            } else {
                $key = $token;
            }

            if ($key === '') {
                continue;
            }

            $parameters[$key] = $value;
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerBrainsCommand(): void
    {
        $this->context->commands()->register('brains', function (array $parameters): CommandResponse {
            return $this->brainsCommand();
        }, [
            'description' => 'List available brains.',
            'group' => 'brain',
            'usage' => 'brains',
        ]);
    }

    private function registerBrainInitCommand(): void
    {
        $this->context->commands()->register('brain init', function (array $parameters): CommandResponse {
            return $this->brainInitCommand($parameters);
        }, [
            'description' => 'Create a new brain and optionally activate it.',
            'group' => 'brain',
            'usage' => 'brain init <slug> [switch=1]',
        ]);
    }

    private function registerBrainSwitchCommand(): void
    {
        $this->context->commands()->register('brain switch', function (array $parameters): CommandResponse {
            return $this->brainSwitchCommand($parameters);
        }, [
            'description' => 'Switch the active brain.',
            'group' => 'brain',
            'usage' => 'brain switch <slug>',
        ]);
    }

    private function registerBrainBackupCommand(): void
    {
        $this->context->commands()->register('brain backup', function (array $parameters): CommandResponse {
            return $this->brainBackupCommand($parameters);
        }, [
            'description' => 'Create a backup copy of a brain.',
            'group' => 'brain',
            'usage' => 'brain backup [slug] [label=name]',
        ]);
    }

    private function registerBrainInfoCommand(): void
    {
        $this->context->commands()->register('brain info', function (array $parameters): CommandResponse {
            return $this->brainInfoCommand($parameters);
        }, [
            'description' => 'Show information about a brain.',
            'group' => 'brain',
            'usage' => 'brain info [slug]',
        ]);
    }

    private function registerBrainValidateCommand(): void
    {
        $this->context->commands()->register('brain validate', function (array $parameters): CommandResponse {
            return $this->brainValidateCommand($parameters);
        }, [
            'description' => 'Run integrity diagnostics for a brain.',
            'group' => 'brain',
            'usage' => 'brain validate [slug]',
        ]);
    }

    private function brainsCommand(): CommandResponse
    {
        try {
            $brains = $this->brains->listBrains();

            return CommandResponse::success('brains', [
                'count' => count($brains),
                'brains' => $brains,
            ], 'Available brains');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list brains', ['exception' => $exception]);

            return CommandResponse::error('brains', 'Unable to list brains.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainInitCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('brain init', 'Parameter "slug" is required.');
        }

        if (strtolower($slug) === 'system') {
            return CommandResponse::error('brain init', 'Cannot create a brain with slug "system".');
        }

        $activate = $this->toBool($parameters['switch'] ?? $parameters['activate'] ?? false);

        try {
            $brain = $this->brains->createBrain($slug, $activate);

            return CommandResponse::success('brain init', [
                'brain' => $brain,
            ], $activate ? sprintf('Brain "%s" created and activated.', $brain['slug']) : sprintf('Brain "%s" created.', $brain['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to create brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain init', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainSwitchCommand(array $parameters): CommandResponse
    {
        $slug = $this->extractSlug($parameters);
        if ($slug === null) {
            return CommandResponse::error('brain switch', 'Parameter "slug" is required.');
        }

        try {
            $brain = $this->brains->setActiveBrain($slug);

            return CommandResponse::success('brain switch', [
                'brain' => $brain,
            ], sprintf('Active brain set to "%s".', $brain['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to switch brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain switch', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainBackupCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;
        $label = $parameters['label'] ?? null;

        try {
            $backup = $this->brains->backupBrain($slug, is_string($label) && $label !== '' ? $label : null);

            return CommandResponse::success('brain backup', $backup, sprintf('Backup created for brain "%s".', $backup['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to backup brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain backup', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainInfoCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;

        try {
            $info = $this->brains->brainReport($slug);

            return CommandResponse::success('brain info', $info, sprintf('Brain details for "%s".', $info['slug']));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to retrieve brain info', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain info', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function brainValidateCommand(array $parameters): CommandResponse
    {
        $slug = $parameters['slug'] ?? null;

        try {
            $report = $this->brains->integrityReportFor($slug ?? '');

            return CommandResponse::success('brain validate', $report, 'Integrity report generated.');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to validate brain', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('brain validate', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function extractSlug(array $parameters): ?string
    {
        foreach (['slug', 'brain', 'name'] as $key) {
            if (!isset($parameters[$key])) {
                continue;
            }

            $value = trim((string) $parameters[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
