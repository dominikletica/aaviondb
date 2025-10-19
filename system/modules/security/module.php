<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Security\SecurityAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new SecurityAgent($context);
        $agent->register();
    },
    'commands' => [
        'security' => [
            'description' => 'Manage security settings, rate limits, and lockdown state.',
            'group' => 'security',
            'usage' => 'security [config|enable|disable|lockdown [seconds]|purge]',
        ],
    ],
];
