<?php

declare(strict_types=1);

namespace AavionDB\Modules\Config;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function array_map;
use function substr;
use function strpos;
use function implode;
use function count;
use function json_decode;
use function json_last_error;
use function str_starts_with;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function trim;
use const JSON_ERROR_NONE;

final class ConfigAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerSetCommand();
        $this->registerGetCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('set', function (ParserContext $context): void {
            $this->configureContext($context, 'set');
        }, 5);

        $this->context->commands()->registerParserHandler('get', function (ParserContext $context): void {
            $this->configureContext($context, 'get');
        }, 5);
    }

    private function configureContext(ParserContext $context, string $action): void
    {
        $tokens = $context->tokens();
        if ($action === 'set') {
            $context->setAction('set');
        } elseif ($action === 'get') {
            $context->setAction('get');
        }

        $parameters = [];
        $key = null;
        $valueParts = [];

        foreach ($tokens as $rawToken) {
            $token = trim($rawToken);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                if ($token === '') {
                    continue;
                }

                $flagKey = $token;
                $flagValue = true;

                if (strpos($token, '=') !== false) {
                    [$flagKey, $flagValue] = array_map('trim', explode('=', $token, 2));
                }

                if ($flagKey === '') {
                    continue;
                }

                $parameters[$flagKey] = $flagValue;
                continue;
            }

            if ($key === null) {
                $key = $token;
                continue;
            }

            $valueParts[] = $token;
        }

        if ($key !== null) {
            $parameters['key'] = $key;
        }

        if ($valueParts !== []) {
            $parameters['value'] = implode(' ', $valueParts);
        }

        if ($context->payload() !== null) {
            $parameters['payload'] = $context->payload();
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerSetCommand(): void
    {
        $this->context->commands()->register('set', function (array $parameters): CommandResponse {
            return $this->setCommand($parameters);
        }, [
            'description' => 'Set or delete a configuration key.',
            'group' => 'config',
            'usage' => 'set <key> [value] [--system=1]',
        ]);
    }

    private function registerGetCommand(): void
    {
        $this->context->commands()->register('get', function (array $parameters): CommandResponse {
            return $this->getCommand($parameters);
        }, [
            'description' => 'Get a configuration value or list all keys.',
            'group' => 'config',
            'usage' => 'get [key] [--system=1]',
        ]);
    }

    private function setCommand(array $parameters): CommandResponse
    {
        $key = isset($parameters['key']) ? trim((string) $parameters['key']) : '';
        if ($key === '') {
            return CommandResponse::error('set', 'Configuration key is required.');
        }

        $system = $this->toBool($parameters['system'] ?? false);

        $valueProvided = false;
        $value = null;

        if (isset($parameters['payload']) && is_array($parameters['payload'])) {
            $valueProvided = true;
            $value = $parameters['payload'];
        } elseif (array_key_exists('value', $parameters)) {
            $valueProvided = true;
            $value = $this->normalizeScalar((string) $parameters['value']);
        }

        try {
            if (!$valueProvided) {
                $this->brains->deleteConfigValue($key, $system);

                return CommandResponse::success('set', [
                    'key' => $key,
                    'system' => $system,
                    'deleted' => true,
                ], sprintf('Config key "%s" removed%s.', $key, $system ? ' (system)' : ''));
            }

            $this->brains->setConfigValue($key, $value, $system);

            return CommandResponse::success('set', [
                'key' => $key,
                'system' => $system,
                'value' => $value,
            ], sprintf('Config key "%s" updated%s.', $key, $system ? ' (system)' : ''));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to set config value', [
                'key' => $key,
                'system' => $system,
                'exception' => $exception,
            ]);

            return CommandResponse::error('set', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function getCommand(array $parameters): CommandResponse
    {
        $system = $this->toBool($parameters['system'] ?? false);
        $key = isset($parameters['key']) ? trim((string) $parameters['key']) : '';

        try {
            if ($key === '') {
                $entries = $this->brains->listConfig($system);

                return CommandResponse::success('get', [
                    'system' => $system,
                    'count' => count($entries),
                    'config' => $entries,
                ], sprintf('Config entries for %s brain.', $system ? 'system' : 'active'));
            }

            $exists = false;
            $value = null;
            $entries = $this->brains->listConfig($system);
            if (array_key_exists($key, $entries)) {
                $exists = true;
                $value = $entries[$key];
            }

            return CommandResponse::success('get', [
                'system' => $system,
                'key' => $key,
                'exists' => $exists,
                'value' => $exists ? $value : null,
            ], $exists
                ? sprintf('Config value for "%s"%s.', $key, $system ? ' (system)' : '')
                : sprintf('Config key "%s" not set%s.', $key, $system ? ' (system)' : ''));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to get config value', [
                'key' => $key,
                'system' => $system,
                'exception' => $exception,
            ]);

            return CommandResponse::error('get', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function normalizeScalar(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $lower = strtolower($trimmed);
        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        ) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
