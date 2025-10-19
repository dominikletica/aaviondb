<?php

use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Modules\Schema\SchemaAgent;

return [
    'init' => static function (ModuleContext $context): void {
        $agent = new SchemaAgent($context);
        $agent->register();
    },
    'commands' => [
        'schema list' => [
            'description' => 'List available fieldset schemas.',
            'group' => 'schema',
            'usage' => 'schema list [with_versions=1]',
        ],
        'schema show' => [
            'description' => 'Show schema payload for a fieldset/version.',
            'group' => 'schema',
            'usage' => 'schema show <fieldset> [@version|#commit]',
        ],
        'schema lint' => [
            'description' => 'Validate a JSON schema definition.',
            'group' => 'schema',
            'usage' => 'schema lint {json schema}',
        ],
    ],
];
