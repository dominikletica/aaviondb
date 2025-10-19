<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Cache\CacheAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new CacheAgent($context);
        $agent->register();
    },
    'commands' => [
        'cache' => [
            'description' => 'Inspect and configure the cache subsystem.',
            'group' => 'cache',
            'usage' => 'cache [status|enable|disable|ttl <seconds>|purge [key=...] [tag=...]]',
        ],
    ],
];
