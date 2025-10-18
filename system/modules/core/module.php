<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Core\CoreAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new CoreAgent($context);
        $agent->register();
    },
    'commands' => [
        'status' => [
            'description' => 'Display a concise runtime status snapshot.',
            'group' => 'core',
            'usage' => 'status',
        ],
        'diagnose' => [
            'description' => 'Return full diagnostic information (paths, modules, brains).',
            'group' => 'core',
            'usage' => 'diagnose',
        ],
        'help' => [
            'description' => 'List available commands or show details for a specific command.',
            'group' => 'core',
            'usage' => 'help [command=name]',
        ],
    ],
];
