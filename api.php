<?php

declare(strict_types=1);

use AavionDB\AavionDB;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/vendor/autoload.php';

$request = Request::createFromGlobals();

$logger = null;

if ($request->getMethod() === 'OPTIONS') {
    $response = new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    $response->send();

    return;
}

try {
    AavionDB::setup();
    $logger = AavionDB::logger();
} catch (\Throwable $exception) {
    if ($logger !== null) {
        $logger->error('Bootstrap failed', ['exception' => $exception]);
    }

    $response = new JsonResponse([
        'status' => 'error',
        'action' => 'bootstrap',
        'message' => 'Failed to initialise AavionDB.',
        'meta' => [
            'exception' => [
                'message' => $exception->getMessage(),
                'type' => \get_class($exception),
            ],
        ],
    ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);

    $response->send();
    return;
}

$action = (string) $request->query->get('action', '');
if ($action === '') {
    $payload = [
        'status' => 'error',
        'action' => 'invalid',
        'message' => 'Query parameter "action" is required.',
        'meta' => [],
    ];

    $response = new JsonResponse($payload, JsonResponse::HTTP_BAD_REQUEST);
    $response->send();

    return;
}

$parameters = $request->query->all();
unset($parameters['action']);

// Include JSON payload if provided.
$rawBody = (string) $request->getContent();
if ($rawBody !== '') {
    try {
        $decoded = \json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $parameters['payload'] = $decoded;
    } catch (\JsonException $exception) {
        $response = new JsonResponse([
            'status' => 'error',
            'action' => $action,
            'message' => 'Invalid JSON payload.',
            'meta' => [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => \get_class($exception),
                ],
            ],
        ], JsonResponse::HTTP_BAD_REQUEST);

        $response->send();

        return;
    }
} elseif ($request->request->count() > 0) {
    $parameters['payload'] = $request->request->all();
}
try {
    $result = AavionDB::run($action, $parameters);
} catch (\Throwable $exception) {
    if ($logger !== null) {
        $logger->error('Unhandled exception during REST dispatch', [
            'action' => $action,
            'exception' => $exception,
        ]);
    }

    $result = [
        'status' => 'error',
        'action' => $action,
        'message' => 'Internal server error.',
        'meta' => [
            'exception' => [
                'message' => $exception->getMessage(),
                'type' => \get_class($exception),
            ],
        ],
        'data' => null,
    ];
}

$statusCode = JsonResponse::HTTP_OK;
if (($result['status'] ?? 'error') !== 'ok') {
    $statusCode = isset($result['meta']['exception']) ? JsonResponse::HTTP_INTERNAL_SERVER_ERROR : JsonResponse::HTTP_BAD_REQUEST;
}

$response = new JsonResponse($result, $statusCode);
$response->headers->set('Access-Control-Allow-Origin', '*');
$response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

$response->send();
