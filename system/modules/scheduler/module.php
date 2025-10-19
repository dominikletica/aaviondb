<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Scheduler\SchedulerAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new SchedulerAgent($context);
        $agent->register();
    },
    'commands' => [
        'scheduler add' => [
            'description' => 'Create a scheduled command.',
            'group' => 'scheduler',
            'usage' => 'scheduler add <slug> <command>',
        ],
        'scheduler edit' => [
            'description' => 'Update a scheduled command.',
            'group' => 'scheduler',
            'usage' => 'scheduler edit <slug> <command>',
        ],
        'scheduler remove' => [
            'description' => 'Remove a scheduled command.',
            'group' => 'scheduler',
            'usage' => 'scheduler remove <slug>',
        ],
        'scheduler list' => [
            'description' => 'List scheduled commands.',
            'group' => 'scheduler',
            'usage' => 'scheduler list',
        ],
        'scheduler log' => [
            'description' => 'Show recent scheduler runs.',
            'group' => 'scheduler',
            'usage' => 'scheduler log [limit=20]',
        ],
        'cron' => [
            'description' => 'Execute all scheduled commands.',
            'group' => 'scheduler',
            'usage' => 'cron',
        ],
    ],
];
