<?php

declare(strict_types=1);

namespace AavionDB\Modules\Core;

use AavionDB\AavionDB;
use AavionDB\Core\Bootstrap;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use function array_unshift;
use function count;
use function in_array;
use function strtolower;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Registers baseline status/diagnostics/help commands for AavionDB.
 */
final class CoreAgent
{
    private ModuleContext $context;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
    }

    public function register(): void
    {
        $this->registerGlobalParsers();
        $this->registerShortcutParsers();
        $this->registerStatusCommand();
        $this->registerDiagnoseCommand();
        $this->registerHelpCommand();
    }

    private function registerGlobalParsers(): void
    {
        $this->context->commands()->registerParserHandler(null, function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $debug = null;
            $filtered = [];

            foreach ($tokens as $token) {
                $normalized = strtolower(trim($token));

                if ($normalized === '--debug' || $normalized === '-d' || $normalized === 'debug') {
                    $debug = true;
                    continue;
                }

                if (str_starts_with($normalized, '--debug=')) {
                    $value = substr($normalized, 8);
                    $flag = $this->normalizeDebugValue($value);
                    if ($flag !== null) {
                        $debug = $flag;
                    }
                    continue;
                }

                if (str_starts_with($normalized, 'debug=')) {
                    $value = substr($normalized, 6);
                    $flag = $this->normalizeDebugValue($value);
                    if ($flag !== null) {
                        $debug = $flag;
                    }
                    continue;
                }

                if ($normalized === '--no-debug') {
                    $debug = false;
                    continue;
                }

                $filtered[] = $token;
            }

            if ($debug !== null) {
                $context->mergeParameters(['debug' => $debug]);
                $context->mergeMetadata(['debug' => $debug]);
            }

            if ($filtered !== $tokens) {
                $context->setTokens($filtered);
            }
        }, 100);
    }

    private function registerShortcutParsers(): void
    {
        $this->context->commands()->registerParserHandler('list', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $subject = strtolower(trim(array_shift($tokens)));

            switch ($subject) {
                case 'projects':
                    $context->setAction('project');
                    array_unshift($tokens, 'list');
                    $context->setTokens($tokens);
                    return;
                case 'entities':
                    $context->setAction('entity');
                    array_unshift($tokens, 'list');
                    $context->setTokens($tokens);
                    return;
                case 'versions':
                    $context->setAction('entity');
                    array_unshift($tokens, 'versions');
                    $context->setTokens($tokens);
                    return;
                case 'commits':
                    $context->setAction('project');
                    array_unshift($tokens, 'commits');
                    $context->setTokens($tokens);
                    return;
                default:
                    return;
            }
        }, 50);

        $this->context->commands()->registerParserHandler('save', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $context->setAction('entity');
            array_unshift($tokens, 'save');
            $context->setTokens($tokens);
        }, 50);

        $this->context->commands()->registerParserHandler('show', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $context->setAction('entity');
            array_unshift($tokens, 'show');
            $context->setTokens($tokens);
        }, 50);

        $this->context->commands()->registerParserHandler('remove', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $context->setAction('entity');
            array_unshift($tokens, 'remove');
            $context->setTokens($tokens);
        }, 50);

        $this->context->commands()->registerParserHandler('restore', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            $context->setAction('entity');
            array_unshift($tokens, 'restore');
            $context->setTokens($tokens);
        }, 50);

        $this->context->commands()->registerParserHandler('delete', function (ParserContext $context): void {
            $tokens = $context->tokens();
            if ($tokens === []) {
                return;
            }

            // Single token -> treat as brain deletion (delete <brain>).
            if (count($tokens) === 1) {
                $context->setAction('brain');
                array_unshift($tokens, 'delete');
                $context->setTokens($tokens);
                return;
            }

            $context->setAction('entity');
            array_unshift($tokens, 'delete');
            $context->setTokens($tokens);
        }, 50);

        $this->context->commands()->registerParserHandler('cleanup', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $context->setAction('brain');
            array_unshift($tokens, 'cleanup');
            $context->setTokens($tokens);
        }, 50);
    }

    private function registerStatusCommand(): void
    {
        $this->context->commands()->register('status', function (array $parameters): CommandResponse {
            return $this->statusCommand();
        }, [
            'description' => 'Display a concise runtime status snapshot.',
            'group' => 'core',
            'usage' => 'status',
        ]);
    }

    private function registerDiagnoseCommand(): void
    {
        $this->context->commands()->register('diagnose', function (array $parameters): CommandResponse {
            return $this->diagnoseCommand();
        }, [
            'description' => 'Return full diagnostic information (paths, modules, brains).',
            'group' => 'core',
            'usage' => 'diagnose',
        ]);
    }

    private function registerHelpCommand(): void
    {
        $this->context->commands()->register('help', function (array $parameters): CommandResponse {
            return $this->helpCommand($parameters);
        }, [
            'description' => 'List available commands or show details for a specific command.',
            'group' => 'core',
            'usage' => 'help [command=name]',
        ]);
    }

    private function normalizeDebugValue(string $value): ?bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function statusCommand(): CommandResponse
    {
        $diagnostics = AavionDB::diagnose();
        $data = $diagnostics['data'] ?? [];

        $brain = $data['brain'] ?? [];
        $modules = $data['modules']['modules'] ?? [];
        $security = $brain['security'] ?? [];

        $activeBrain = null;
        if (isset($brain['active_brain']) && \is_array($brain['active_brain'])) {
            $activeBrain = [
                'slug' => $brain['active_brain']['slug'] ?? null,
                'path' => $brain['active_brain']['path'] ?? null,
                'exists' => $brain['active_brain']['exists'] ?? null,
                'last_modified' => $brain['active_brain']['modified_at'] ?? null,
            ];
        }

        $moduleList = [];
        foreach ($modules as $module) {
            if (!\is_array($module)) {
                continue;
            }

            $moduleList[] = [
                'slug' => $module['slug'] ?? null,
                'name' => $module['name'] ?? null,
                'scope' => $module['scope'] ?? null,
                'version' => $module['version'] ?? null,
                'issues' => $module['issues'] ?? [],
            ];
        }

        $summary = [
            'framework' => [
                'version' => Bootstrap::VERSION,
                'booted_at' => $data['booted_at'] ?? null,
                'root_path' => $data['paths']['root'] ?? null,
            ],
            'brain' => [
                'active' => $activeBrain,
                'api_enabled' => $security['api_enabled'] ?? null,
                'bootstrap_active' => $security['bootstrap_active'] ?? null,
                'active_keys' => $security['active_keys'] ?? null,
            ],
            'modules' => [
                'count' => \count($moduleList),
                'items' => $moduleList,
                'errors' => $data['modules']['initialisation_errors'] ?? [],
            ],
        ];

        $summary = $this->enrichBrainFootprint($summary);

        return CommandResponse::success('status', $summary, 'AavionDB status snapshot');
    }

    private function enrichBrainFootprint(array $summary): array
    {
        $slug = $summary['brain']['active']['slug'] ?? null;

        try {
            $report = $this->context->brains()->brainReport($slug);
            $summary['brain']['footprint'] = [
                'bytes' => $report['size_bytes'] ?? null,
                'entity_versions' => $report['entity_versions'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $summary['brain']['footprint'] = [
                'bytes' => null,
                'entity_versions' => null,
                'error' => $exception->getMessage(),
            ];
        }

        return $summary;
    }

    private function diagnoseCommand(): CommandResponse
    {
        return CommandResponse::fromPayload('diagnose', AavionDB::diagnose());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function helpCommand(array $parameters): CommandResponse
    {
        $target = isset($parameters['command']) ? \strtolower(\trim((string) $parameters['command'])) : null;

        $commands = $this->context->commands()->all();
        \usort($commands, static function (array $a, array $b): int {
            return \strcmp($a['name'], $b['name']);
        });

        if ($target !== null && $target !== '') {
            foreach ($commands as $command) {
                if ($command['name'] !== $target) {
                    continue;
                }

                $meta = $command['meta'] ?? [];

                return CommandResponse::success('help', [
                    'command' => $command['name'],
                    'description' => $meta['description'] ?? null,
                    'usage' => $meta['usage'] ?? null,
                    'group' => $meta['group'] ?? null,
                    'meta' => $meta,
                ], sprintf('Details for command "%s".', $command['name']));
            }

            return CommandResponse::error('help', sprintf('Unknown command "%s".', $target));
        }

        $list = array_map(static function (array $command): array {
            $meta = $command['meta'] ?? [];

            return [
                'name' => $command['name'],
                'description' => $meta['description'] ?? null,
                'usage' => $meta['usage'] ?? null,
                'group' => $meta['group'] ?? null,
            ];
        }, $commands);

        return CommandResponse::success('help', [
            'count' => \count($list),
            'commands' => $list,
        ], 'Available commands');
    }
}
