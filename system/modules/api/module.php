<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Api\ApiAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new ApiAgent($context);
        $agent->register();
    },
    'commands' => [
        'api serve' => [
            'description' => 'Enable the REST API once tokens are provisioned.',
            'group' => 'api',
            'usage' => 'api serve [reason=text]',
        ],
        'api stop' => [
            'description' => 'Disable the REST API (no requests accepted).',
            'group' => 'api',
            'usage' => 'api stop [reason=text]',
        ],
        'api status' => [
            'description' => 'Show REST API status and telemetry.',
            'group' => 'api',
            'usage' => 'api status',
        ],
        'api reset' => [
            'description' => 'Disable REST and revoke issued API tokens.',
            'group' => 'api',
            'usage' => 'api reset',
        ],
    ],
];
