<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Resolver\ResolverAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new ResolverAgent($context);
        $agent->register();
    },
    'commands' => [
        'resolve' => [
            'description' => 'Resolve a single shortcode ([ref]/[query]) in the context of an entity.',
            'group' => 'resolver',
            'usage' => 'resolve [shortcode] --source=project.entity[@version|#commit] [--param.foo=value]',
        ],
    ],
];
