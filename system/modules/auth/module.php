<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Auth\AuthAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new AuthAgent($context);
        $agent->register();
    },
    'commands' => [
        'auth grant' => [
            'description' => 'Generate a new API token with scope and project restrictions.',
            'group' => 'auth',
            'usage' => 'auth grant [scope=RW] [projects=*] [label=name]',
        ],
        'auth list' => [
            'description' => 'List existing API tokens (masked).',
            'group' => 'auth',
            'usage' => 'auth list',
        ],
        'auth revoke' => [
            'description' => 'Revoke an API token by hash/token.',
            'group' => 'auth',
            'usage' => 'auth revoke <token|hash>',
        ],
        'auth reset' => [
            'description' => 'Revoke all tokens and disable REST API.',
            'group' => 'auth',
            'usage' => 'auth reset',
        ],
    ],
];
