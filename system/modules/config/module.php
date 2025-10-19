<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Config\ConfigAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new ConfigAgent($context);
        $agent->register();
    },
    'commands' => [
        'set' => [
            'description' => 'Set or delete a configuration key in the active brain (use --system for system brain).',
            'group' => 'config',
            'usage' => 'set <key> [value] [--system=1]',
        ],
        'get' => [
            'description' => 'Get a configuration value or list all keys when no key is provided.',
            'group' => 'config',
            'usage' => 'get [key] [--system=1]',
        ],
    ],
];
