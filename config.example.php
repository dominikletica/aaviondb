<?php

return [
    // Master admin secret (must start with "_" and be >= 8 characters). Empty string disables the bypass.
    'admin_secret' => '',

    // Default user brain slug created/activated on first setup.
    'default_brain' => 'default',

    // Storage paths (override PathLocator defaults if needed).
    'backups_path' => 'user/backups',
    'exports_path' => 'user/exports',
    'log_path' => 'system/storage/logs',

    // API key generation parameters.
    'api_key_length' => 16,
];
