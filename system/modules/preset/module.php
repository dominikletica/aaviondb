<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Preset\PresetAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new PresetAgent($context);
        $agent->register();
    },
    'commands' => [
        'preset list' => [
            'description' => 'List available export presets.',
            'group' => 'export',
        ],
        'preset show' => [
            'description' => 'Show details of a preset definition.',
            'group' => 'export',
        ],
        'preset create' => [
            'description' => 'Create a new export preset.',
            'group' => 'export',
        ],
        'preset update' => [
            'description' => 'Update an existing export preset.',
            'group' => 'export',
        ],
        'preset delete' => [
            'description' => 'Delete an export preset.',
            'group' => 'export',
        ],
        'preset copy' => [
            'description' => 'Copy an export preset to a new slug.',
            'group' => 'export',
        ],
        'preset import' => [
            'description' => 'Import a preset definition from a JSON file.',
            'group' => 'export',
        ],
        'preset export' => [
            'description' => 'Export a preset definition to disk.',
            'group' => 'export',
        ],
    ],
];
