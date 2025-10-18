<?php

declare(strict_types=1);

namespace AavionDB\Modules\Core;

use AavionDB\AavionDB;
use AavionDB\Core\Bootstrap;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;

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
        $this->registerStatusCommand();
        $this->registerDiagnoseCommand();
        $this->registerHelpCommand();
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
