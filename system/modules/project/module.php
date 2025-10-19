<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Project\ProjectAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new ProjectAgent($context);
        $agent->register();
    },
    'commands' => [
        'project list' => [
            'description' => 'List projects in the active brain.',
            'group' => 'project',
            'usage' => 'project list',
        ],
        'project create' => [
            'description' => 'Create a new project (optionally with title/description).',
            'group' => 'project',
            'usage' => 'project create <slug> [title="My Project"] [description="Project description"]',
        ],
        'project update' => [
            'description' => 'Update project metadata (title/description).',
            'group' => 'project',
            'usage' => 'project update <slug> [title="New Title"] [description="New description"]',
        ],
        'project remove' => [
            'description' => 'Archive a project (soft delete).',
            'group' => 'project',
            'usage' => 'project remove <slug>',
        ],
        'project delete' => [
            'description' => 'Permanently delete a project (dangerous).',
            'group' => 'project',
            'usage' => 'project delete <slug> [purge_commits=1]',
        ],
        'project info' => [
            'description' => 'Show details and entity summary for a project.',
            'group' => 'project',
            'usage' => 'project info <slug>',
        ],
    ],
];
