<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Export\ExportAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new ExportAgent($context);
        $agent->register();
    },
    'commands' => [
        'export' => [
            'description' => 'Generate JSON exports for a project or the entire brain.',
            'group' => 'export',
            'usage' => 'export <project|*> [entity[,entity[@version|#commit]]] [description="How to use this export"] [usage="LLM guidance"]',
        ],
    ],
];
