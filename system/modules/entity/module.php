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
            'usage' => 'entity list <project> [with_versions=1]',
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
        'entity delete' => [
            'description' => 'Archive or purge an entity.',
            'group' => 'entity',
            'usage' => 'entity delete <project> <entity> [purge=0]',
        ],
        'entity restore' => [
            'description' => 'Restore an entity to a previous version.',
            'group' => 'entity',
            'usage' => 'entity restore <project> <entity> <@version|#commit>',
        ],
    ],
];
