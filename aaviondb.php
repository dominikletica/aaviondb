<?php

declare(strict_types=1);

use AavionDB\AavionDB;

require __DIR__ . '/vendor/autoload.php';

if (!AavionDB::isBooted()) {
    AavionDB::setup();
}

return AavionDB::class;
