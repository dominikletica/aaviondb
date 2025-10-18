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
            'usage' => 'brain backup [slug] [label=name]',
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
    ],
];
