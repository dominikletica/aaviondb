<?php

declare(strict_types=1);

namespace AavionDB\Modules\Log;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_key_exists;
use function array_map;
use function array_reverse;
use function in_array;
use function explode;
use function file;
use function implode;
use function is_file;
use function is_numeric;
use function json_decode;
use function json_last_error;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strtoupper;
use function trim;

final class LogAgent
{
    private const DEFAULT_LEVEL = 'ERROR';

    private const DEFAULT_LIMIT = 10;

    /**
     * @var string[]
     */
    private const SUPPORTED_LEVELS = ['ERROR', 'AUTH', 'DEBUG', 'ALL'];

    private ModuleContext $context;

    private PathLocator $paths;

    private LoggerInterface $logger;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->paths = $context->paths();
        $this->logger = $context->logger();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerLogCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('log', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $parameters = [];

            if ($tokens !== []) {
                $first = $tokens[0];
                if (!$this->looksLikeNamedArgument($first)) {
                    $parameters['level'] = array_shift($tokens);
                }
            }

            if ($tokens !== []) {
                $maybeLimit = $tokens[0];
                if (!$this->looksLikeNamedArgument($maybeLimit)) {
                    $parameters['limit'] = array_shift($tokens);
                }
            }

            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }

                if ($this->looksLikeNamedArgument($token)) {
                    [$key, $value] = $this->splitArgument($token);
                    if ($key !== null) {
                        $parameters[$key] = $value;
                    }
                    continue;
                }
            }

            $context->setAction('log');
            $context->mergeParameters($parameters);
            $context->setTokens([]);
        }, 10);
    }

    private function registerLogCommand(): void
    {
        $this->context->commands()->register('log', function (array $parameters): CommandResponse {
            return $this->logCommand($parameters);
        }, [
            'description' => 'Tail the framework log with optional level and limit filters.',
            'group' => 'log',
            'usage' => 'log [level=ERROR|AUTH|DEBUG|ALL] [limit=10]',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function logCommand(array $parameters): CommandResponse
    {
        $level = isset($parameters['level']) ? strtoupper(trim((string) $parameters['level'])) : self::DEFAULT_LEVEL;
        if ($level === '') {
            $level = self::DEFAULT_LEVEL;
        }

        if (!in_array($level, self::SUPPORTED_LEVELS, true)) {
            return CommandResponse::error('log', sprintf(
                'Invalid level "%s". Allowed: %s.',
                $level,
                implode(', ', self::SUPPORTED_LEVELS)
            ));
        }

        $limitValue = $parameters['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_numeric($limitValue)) {
            return CommandResponse::error('log', 'Limit must be a numeric value.');
        }

        $limit = (int) $limitValue;
        if ($limit <= 0) {
            return CommandResponse::error('log', 'Limit must be greater than zero.');
        }

        $logFile = $this->paths->systemLogs() . DIRECTORY_SEPARATOR . 'aaviondb.log';
        if (!is_file($logFile)) {
            return CommandResponse::success('log', [
                'level' => $level,
                'limit' => $limit,
                'entries' => [],
                'path' => $logFile,
            ], 'Log file not found; no entries to display.');
        }

        try {
            $entries = $this->readLogEntries($logFile, $level, $limit);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to read log file.', [
                'path' => $logFile,
                'exception' => $exception,
            ]);

            return CommandResponse::error('log', 'Unable to read log file.', [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        $message = $entries === []
            ? sprintf('No entries matched level "%s".', $level)
            : sprintf('Showing up to %d "%s" entries.', $limit, $level);

        return CommandResponse::success('log', [
            'level' => $level,
            'limit' => $limit,
            'path' => $logFile,
            'entries' => $entries,
        ], $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLogEntries(string $path, string $levelFilter, int $limit): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException(sprintf('Unable to read log file "%s".', $path));
        }

        $entries = [];
        for ($index = count($lines) - 1; $index >= 0; --$index) {
            $line = trim((string) $lines[$index]);
            if ($line === '') {
                continue;
            }

            $entry = $this->parseLogLine($line);
            if (!$this->matchesFilter($entry, $levelFilter)) {
                continue;
            }

            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return array_reverse($entries);
    }

    private function matchesFilter(array $entry, string $filter): bool
    {
        if ($filter === 'ALL') {
            return true;
        }

        $line = $entry['raw'] ?? '';
        $level = $entry['level'] ?? null;
        $context = $entry['context'] ?? [];

        return match ($filter) {
            'ERROR' => $level !== null && in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true),
            'DEBUG' => $level === 'DEBUG',
            'AUTH' => (
                (is_array($context) && array_key_exists('category', $context) && strtoupper((string) $context['category']) === 'AUTH')
                || ($level !== null && $level === 'NOTICE' && str_contains($line, '"category":"AUTH"'))
                || str_contains($line, '"category":"AUTH"')
            ),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLogLine(string $line): array
    {
        $result = [
            'raw' => $line,
        ];

        if (!preg_match('/^\[(?P<date>.*?)\]\s(?P<channel>[A-Za-z0-9_\\\\]+)\.(?P<level>[A-Z]+):\s(?P<body>.*)$/', $line, $matches)) {
            return $result;
        }

        $result['timestamp'] = $matches['date'];
        $result['channel'] = $matches['channel'];
        $result['level'] = $matches['level'];

        $body = $matches['body'];
        $contextRaw = null;
        $extraRaw = null;

        if (preg_match('/^(?P<message>.*?)\s(?P<context>\{.*\})\s(?P<extra>\[.*\])$/', $body, $parts)) {
            $body = $parts['message'];
            $contextRaw = $parts['context'];
            $extraRaw = $parts['extra'];
        } elseif (preg_match('/^(?P<message>.*?)\s(?P<context>\{.*\})$/', $body, $parts)) {
            $body = $parts['message'];
            $contextRaw = $parts['context'];
        }

        $result['message'] = trim($body);
        $result['context'] = $this->decodeContext($contextRaw);
        $result['extra'] = $extraRaw;

        if ($result['context'] === null && $contextRaw !== null) {
            $result['context_raw'] = $contextRaw;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeContext(?string $contextRaw): ?array
    {
        if ($contextRaw === null || $contextRaw === '{}') {
            return null;
        }

        $decoded = json_decode($contextRaw, true);
        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    private function looksLikeNamedArgument(string $token): bool
    {
        if (str_starts_with($token, '--')) {
            return true;
        }

        return str_contains($token, '=');
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitArgument(string $token): array
    {
        if (str_starts_with($token, '--')) {
            $token = substr($token, 2);
        }

        if (!str_contains($token, '=')) {
            return [null, null];
        }

        [$key, $value] = array_map('trim', explode('=', $token, 2));

        return [$key === '' ? null : $key, $value];
    }
}
