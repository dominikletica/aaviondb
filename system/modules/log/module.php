<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Log\LogAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new LogAgent($context);
        $agent->register();
    },
    'commands' => [
        'log' => [
            'description' => 'Inspect framework logs with optional level and limit filters.',
            'group' => 'log',
            'usage' => 'log [level=ERROR|AUTH|DEBUG|ALL] [limit=10]',
        ],
    ],
];
