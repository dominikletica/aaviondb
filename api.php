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
    $security = AavionDB::security();
} catch (\Throwable $exception) {
    if ($logger !== null) {
        $logger->error('Bootstrap failed', [
            'exception' => $exception,
            'source' => 'rest:api',
        ]);
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

$parameters = $request->query->all();

$action = '';
if (isset($parameters['action'])) {
    $action = (string) $parameters['action'];
    unset($parameters['action']);
}

$commandStatement = '';
if (isset($parameters['command']) && \is_string($parameters['command'])) {
    $commandStatement = \trim((string) $parameters['command']);
    unset($parameters['command']);
}

$normalizeBoolean = static function ($value): ?bool {
    if (\is_bool($value)) {
        return $value;
    }

    if (\is_numeric($value)) {
        return ((int) $value) === 1;
    }

    if (\is_string($value)) {
        $normalized = \strtolower(\trim($value));

        if ($normalized === '') {
            return null;
        }

        if (\in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (\in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
    }

    return null;
};

$debugFlag = null;
if (\array_key_exists('debug', $parameters)) {
    $candidate = $normalizeBoolean($parameters['debug']);
    if ($candidate !== null) {
        $debugFlag = $candidate;
        $parameters['debug'] = $candidate;
    } else {
        unset($parameters['debug']);
    }
}

// Include JSON payload if provided.
$rawBody = (string) $request->getContent();
if ($rawBody !== '') {
    try {
        $decoded = \json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        if (\is_array($decoded)) {
            if (isset($decoded['command']) && \is_string($decoded['command']) && $commandStatement === '') {
                $commandStatement = \trim((string) $decoded['command']);
            }

            if (\array_key_exists('debug', $decoded) && $debugFlag === null) {
                $candidate = $normalizeBoolean($decoded['debug']);
                if ($candidate !== null) {
                    $debugFlag = $candidate;
                    $parameters['debug'] = $candidate;
                }
            }

            unset($decoded['command'], $decoded['debug']);

            if ($decoded !== []) {
                $payloadData = $decoded;

                if (\array_key_exists('payload', $payloadData)) {
                    $parameters['payload'] = $payloadData['payload'];
                    unset($payloadData['payload']);
                } elseif ($payloadData !== []) {
                    $parameters['payload'] = $payloadData;
                    $payloadData = [];
                }

                foreach ($payloadData as $key => $value) {
                    if (!\array_key_exists($key, $parameters)) {
                        $parameters[$key] = $value;
                    }
                }
            }
        } else {
            $parameters['payload'] = $decoded;
        }
    } catch (\JsonException $exception) {
        $response = new JsonResponse([
            'status' => 'error',
            'action' => $action !== '' ? $action : 'command',
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
    $formPayload = $request->request->all();

    if (isset($formPayload['command']) && \is_string($formPayload['command']) && $commandStatement === '') {
        $commandStatement = \trim((string) $formPayload['command']);
    }

    if (\array_key_exists('debug', $formPayload) && $debugFlag === null) {
        $candidate = $normalizeBoolean($formPayload['debug']);
        if ($candidate !== null) {
            $debugFlag = $candidate;
            $parameters['debug'] = $candidate;
        }
    }

    unset($formPayload['command'], $formPayload['debug']);

    if ($formPayload !== []) {
        $parameters['payload'] = $formPayload;
    }
}

if ($action === '' && $commandStatement !== '') {
    $action = 'command';
}

if ($action === '') {
    $payload = [
        'status' => 'error',
        'action' => 'invalid',
        'message' => 'Either query parameter "action" or "command" is required.',
        'meta' => [],
    ];

    $response = new JsonResponse($payload, JsonResponse::HTTP_BAD_REQUEST);
    $response->send();

    return;
}

$apiToken = null;
$authorization = $request->headers->get('Authorization');
if (
    \is_string($authorization)
    && \preg_match('/Bearer\s+(.+)/i', $authorization, $matches)
) {
    $apiToken = $matches[1] ?? null;
}

if ($apiToken === null) {
    $headerToken = $request->headers->get('X-API-Key');
    if (\is_string($headerToken) && $headerToken !== '') {
        $apiToken = $headerToken;
    }
}

if ($apiToken === null) {
    $queryToken = $request->query->get('token', $request->query->get('api_key'));
    if (\is_string($queryToken) && $queryToken !== '') {
        $apiToken = $queryToken;
    }
}

if ($apiToken === null && $request->request->count() > 0) {
    $bodyToken = $request->request->get('token', $request->request->get('api_key'));
    if (\is_string($bodyToken) && $bodyToken !== '') {
        $apiToken = $bodyToken;
    }
}

$clientKey = (string) ($request->getClientIp() ?? 'unknown');
if ($clientKey === '') {
    $clientKey = 'unknown';
}

$unauthenticatedCron = \strtolower($action) === 'cron';
$tokenPreview = null;
if ($apiToken !== null) {
    $tokenPreview = \substr($apiToken, 0, 8);
    if (\strlen($apiToken) > 8) {
        $tokenPreview .= '…';
    }
}

$securityContext = [
    'action' => $action,
    'ip' => $clientKey,
    'unauthenticated' => $unauthenticatedCron,
    'debug' => $debugFlag ?? false,
    'command' => $commandStatement !== '' ? $commandStatement : null,
    'source' => 'rest:api',
];

if ($tokenPreview !== null) {
    $securityContext['token_preview'] = $tokenPreview;
}

$preflight = $security->preflight($clientKey, $securityContext);
if ($preflight !== null) {
    $response = new JsonResponse($preflight['payload'], $preflight['status_code']);
    foreach (($preflight['headers'] ?? []) as $header => $value) {
        if ($value !== null) {
            $response->headers->set((string) $header, (string) $value);
        }
    }
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    $response->send();

    return;
}

$attempt = $security->registerAttempt($clientKey, $securityContext);
if ($attempt !== null) {
    $response = new JsonResponse($attempt['payload'], $attempt['status_code']);
    foreach (($attempt['headers'] ?? []) as $header => $value) {
        if ($value !== null) {
            $response->headers->set((string) $header, (string) $value);
        }
    }
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    $response->send();

    return;
}

$guard = ['allowed' => true, 'scope' => null];

if (!$unauthenticatedCron) {
    $guard = AavionDB::auth()->guardRestAccess($apiToken, $action);
    if (($guard['allowed'] ?? false) !== true) {
        $failure = $security->registerFailure($clientKey, [
            'action' => $action,
            'reason' => $guard['payload']['meta']['reason'] ?? null,
            'command' => $commandStatement !== '' ? $commandStatement : null,
            'debug' => $debugFlag ?? false,
            'source' => 'rest:api',
        ]);

        $response = new JsonResponse($guard['payload'] ?? [], $guard['status_code'] ?? JsonResponse::HTTP_UNAUTHORIZED);
        if (($failure['blocked'] ?? false) === true && isset($failure['retry_after'])) {
            $response->headers->set('Retry-After', (string) max(1, (int) $failure['retry_after']));
        }
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

        $response->send();

        return;
    }
}

$scope = $guard['scope'] ?? null;
$dispatcher = static function () use ($action, $parameters, $commandStatement, $debugFlag): array {
    if ($commandStatement !== '') {
        $statement = $commandStatement;
        if ($debugFlag === true && !\str_contains($statement, '--debug')) {
            $statement .= ' --debug';
        }
        if (isset($parameters['payload']) && \is_array($parameters['payload']) && !\str_contains($statement, '{')) {
            $encodedPayload = \json_encode($parameters['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedPayload !== false) {
                $statement .= ' ' . $encodedPayload;
            }
        }

        return AavionDB::command($statement);
    }

    return AavionDB::run($action, $parameters);
};

AavionDB::debugLog('Dispatching REST command.', [
    'action' => $action,
    'command' => $commandStatement !== '' ? $commandStatement : null,
    'scope' => $scope,
    'parameters' => $parameters,
    'source' => 'rest:api',
]);

try {
    $result = \is_array($scope)
        ? AavionDB::withScope($scope, $dispatcher)
        : $dispatcher();
} catch (\Throwable $exception) {
    if ($logger !== null) {
        $logger->error('Unhandled exception during REST dispatch', [
            'action' => $action,
            'exception' => $exception,
            'command' => $commandStatement !== '' ? $commandStatement : null,
            'source' => 'rest:api',
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

AavionDB::debugLog('REST command completed.', [
    'action' => $action,
    'command' => $commandStatement !== '' ? $commandStatement : null,
    'status' => $result['status'] ?? null,
    'source' => 'rest:api',
]);

if (($guard['allowed'] ?? true) === true) {
    $security->registerSuccess($clientKey, [
        'action' => $action,
        'mode' => $unauthenticatedCron
            ? 'cron'
            : ($guard['payload']['meta']['mode'] ?? null),
        'token_preview' => $tokenPreview,
        'command' => $commandStatement !== '' ? $commandStatement : null,
        'debug' => $debugFlag ?? false,
        'source' => 'rest:api',
    ]);
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
