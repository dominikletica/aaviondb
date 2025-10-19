<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Events\EventsAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new EventsAgent($context);
        $agent->register();
    },
    'commands' => [
        'events' => [
            'description' => 'Inspect event bus listeners and statistics.',
            'group' => 'events',
            'usage' => 'events [listeners]',
        ],
    ],
];
