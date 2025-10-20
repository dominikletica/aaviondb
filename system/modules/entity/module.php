<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Entity\EntityAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new EntityAgent($context);
        $agent->register();
    },
    'commands' => [
        'entity list' => [
            'description' => 'List entities inside a project.',
            'group' => 'entity',
            'usage' => 'entity list <project> [parent/path] [with_versions=1]',
        ],
        'entity show' => [
            'description' => 'Display specific entity version information.',
            'group' => 'entity',
            'usage' => 'entity show <project> <entity> [@version|#commit]',
        ],
        'entity save' => [
            'description' => 'Persist an entity payload as a new version.',
            'group' => 'entity',
            'usage' => 'entity save <project> <entity[@version|#commit][:fieldset[@version|#commit]]> {json payload}',
        ],
        'entity move' => [
            'description' => 'Move an entity and its descendants to a new hierarchy path.',
            'group' => 'entity',
            'usage' => 'entity move <project> <source-path> <target-path> [--mode=merge|replace]',
        ],
        'entity remove' => [
            'description' => 'Deactivate the active version of one or more entities.',
            'group' => 'entity',
            'usage' => 'entity remove <project> <entity[,entity2]> [--recursive=0|1]',
        ],
        'entity delete' => [
            'description' => 'Delete entire entities (all versions) or targeted revisions (@version/#commit).',
            'group' => 'entity',
            'usage' => 'entity delete <project> <entity[@version|#commit][,entity2[@version|#commit]] [--recursive=0|1] [--purge_commits=0|1]',
        ],
        'entity restore' => [
            'description' => 'Restore an entity to a previous version.',
            'group' => 'entity',
            'usage' => 'entity restore <project> <entity> <@version|#commit>',
        ],
    ],
];
