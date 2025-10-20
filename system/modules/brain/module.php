<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Brain\BrainAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new BrainAgent($context);
        $agent->register();
    },
    'commands' => [
        'brains' => [
            'description' => 'List system and user brains with basic metadata.',
            'group' => 'brain',
            'usage' => 'brains',
        ],
        'brain init' => [
            'description' => 'Create a new brain and optionally activate it.',
            'group' => 'brain',
            'usage' => 'brain init <slug> [switch=1]',
        ],
        'brain switch' => [
            'description' => 'Activate an existing brain.',
            'group' => 'brain',
            'usage' => 'brain switch <slug>',
        ],
        'brain backup' => [
            'description' => 'Create a snapshot copy of a brain.',
            'group' => 'brain',
            'usage' => 'brain backup [slug] [label=name] [--compress=0|1]',
        ],
        'brain backups' => [
            'description' => 'List stored brain backups.',
            'group' => 'brain',
            'usage' => 'brain backups [slug]',
        ],
        'brain backup prune' => [
            'description' => 'Prune backups by keep count or age.',
            'group' => 'brain',
            'usage' => 'brain backup prune <slug|*> [--keep=10] [--older-than=30] [--dry-run=1]',
        ],
        'brain info' => [
            'description' => 'Show detailed information about a brain.',
            'group' => 'brain',
            'usage' => 'brain info [slug]',
        ],
        'brain validate' => [
            'description' => 'Run integrity diagnostics for a brain.',
            'group' => 'brain',
            'usage' => 'brain validate [slug]',
        ],
        'brain cleanup' => [
            'description' => 'Purge inactive versions for a project.',
            'group' => 'brain',
            'usage' => 'brain cleanup <project> [entity] [keep=0] [--dry-run=1]',
        ],
        'brain compact' => [
            'description' => 'Rebuild commit indexes and reorder versions.',
            'group' => 'brain',
            'usage' => 'brain compact [project] [--dry-run=1]',
        ],
        'brain repair' => [
            'description' => 'Repair entity metadata (active versions, statuses, timestamps).',
            'group' => 'brain',
            'usage' => 'brain repair [project] [--dry-run=1]',
        ],
        'brain restore' => [
            'description' => 'Restore a brain from a backup file.',
            'group' => 'brain',
            'usage' => 'brain restore <backup> [target] [--overwrite=0] [--activate=0]',
        ],
    ],
];
