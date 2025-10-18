<?php

declare(strict_types=1);

use AavionDB\AavionDB;

require __DIR__ . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$argc = $_SERVER['argc'] ?? 0;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php cli.php <command>\n");
    exit(1);
}

try {
    AavionDB::setup();
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Failed to initialise AavionDB: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$statement = implode(' ', array_slice($argv, 1));

try {
    $result = AavionDB::command($statement);
} catch (\Throwable $exception) {
    AavionDB::logger()->error('Unhandled CLI exception', [
        'statement' => $statement,
        'exception' => $exception,
    ]);

    $result = [
        'status' => 'error',
        'action' => 'cli',
        'message' => 'Internal CLI error.',
        'meta' => [
            'exception' => [
                'message' => $exception->getMessage(),
                'type' => \get_class($exception),
            ],
        ],
        'data' => null,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(($result['status'] ?? 'error') === 'ok' ? 0 : 1);
