<?php

declare(strict_types=1);

namespace AavionDB\Core;

use AavionDB\Core\Exceptions\CommandException;
use AavionDB\Core\EventBus;
use JsonException;

/**
 * Parses human-readable command statements into structured payloads.
 */
final class CommandParser
{
    private EventBus $events;

    /**
     * @var array<int, array{priority: int, handler: callable(ParserContext): void}>
     */
    private array $globalHandlers = [];

    /**
     * @var array<string, array<int, array{priority: int, handler: callable(ParserContext): void}>>
     */
    private array $actionHandlers = [];

    public function __construct(EventBus $events)
    {
        $this->events = $events;
    }

    /**
     * Registers a parser handler.
     *
     * @param callable(ParserContext): void $handler
     */
    public function registerHandler(?string $action, callable $handler, int $priority = 0): void
    {
        $bucket = &$this->globalHandlers;

        if ($action !== null) {
            $action = \strtolower(\trim($action));
            if ($action === '') {
                throw new CommandException('Parser handler action name must not be empty.');
            }

            if (!isset($this->actionHandlers[$action])) {
                $this->actionHandlers[$action] = [];
            }
            $bucket = &$this->actionHandlers[$action];
        }

        $bucket[] = [
            'priority' => $priority,
            'handler' => $handler,
        ];

        \usort($bucket, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });
    }

    public function parse(string $statement): ParsedCommand
    {
        $statement = \trim($statement);

        if ($statement === '') {
            throw new CommandException('Command statement must not be empty.');
        }

        [$initialAction, $arguments] = $this->splitActionAndArguments($statement);
        $baseline = $this->baselineParse($initialAction, $arguments, $statement);

        $context = new ParserContext(
            $statement,
            $baseline['action'],
            $baseline['raw_arguments'],
            $baseline['raw_json'],
            $baseline['tokens'],
            $baseline['payload']
        );

        $this->invokeHandlers($this->globalHandlers, $context);

        if (!$context->isPropagationStopped()) {
            $processed = [];

            while (!$context->isPropagationStopped()) {
                $action = $context->action();

                if (isset($processed[$action])) {
                    break;
                }
                $processed[$action] = true;

                $handlers = $this->actionHandlers[$action] ?? [];
                if ($handlers === []) {
                    break;
                }

                $this->invokeHandlers($handlers, $context);

                if ($context->isPropagationStopped()) {
                    break;
                }

                // Re-run if action changed to a new value with unprocessed handlers.
                if (!isset($processed[$context->action()])) {
                    continue;
                }

                break;
            }
        }

        $parsed = $context->finalize();

        $this->events->emit('command.parser.parsed', [
            'action' => $parsed->action(),
            'raw' => $parsed->rawStatement(),
            'tokens' => $parsed->tokens(),
        ]);

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $actions = [];
        foreach ($this->actionHandlers as $action => $handlers) {
            $actions[$action] = \count($handlers);
        }

        return [
            'global_handlers' => \count($this->globalHandlers),
            'action_handlers' => $actions,
        ];
    }

    /**
     * @param array<int, array{priority: int, handler: callable(ParserContext): void}> $handlers
     */
    private function invokeHandlers(array $handlers, ParserContext $context): void
    {
        foreach ($handlers as $entry) {
            $handler = $entry['handler'];
            $handler($context);

            if ($context->isPropagationStopped()) {
                break;
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitActionAndArguments(string $statement): array
    {
        $parts = \preg_split('/\s+/', $statement, 2, PREG_SPLIT_NO_EMPTY);

        if (!$parts) {
            throw new CommandException('Unable to resolve command action.');
        }

        $action = \strtolower($parts[0]);
        $arguments = $parts[1] ?? '';

        return [$action, $arguments];
    }

    /**
     * @return array{
     *   action: string,
     *   raw_arguments: string,
     *   raw_json: ?string,
     *   tokens: array<int, string>,
     *   payload: mixed
     * }
     */
    private function baselineParse(string $action, string $arguments, string $statement): array
    {
        $rawJson = null;
        $payload = null;
        $argumentTokens = [];

        $arguments = \trim($arguments);

        if ($arguments !== '') {
            [$tokenSegment, $jsonSegment, $payload] = $this->extractJsonPayload($arguments);
            $argumentTokens = $this->tokenizeArguments($tokenSegment);
            $rawJson = $jsonSegment;
        }

        return [
            'action' => $action,
            'raw_arguments' => $arguments,
            'raw_json' => $rawJson,
            'tokens' => $argumentTokens,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: mixed}
     */
    private function extractJsonPayload(string $arguments): array
    {
        $length = \strlen($arguments);

        $inSingle = false;
        $inDouble = false;
        $escaped = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $arguments[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && ($char === '{' || $char === '[')) {
                $before = \trim(\substr($arguments, 0, $i));
                $jsonString = \trim(\substr($arguments, $i));

                try {
                    $decoded = \json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new CommandException(
                        'Failed to parse JSON payload: ' . $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception
                    );
                }

                return [$before, $jsonString, $decoded];
            }
        }

        return [$arguments, null, null];
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeArguments(string $arguments): array
    {
        if ($arguments === '') {
            return [];
        }

        \preg_match_all(
            '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'|[^\s]+/',
            $arguments,
            $matches
        );

        $tokens = [];

        foreach ($matches[0] as $token) {
            $tokens[] = $this->normalizeToken($token);
        }

        return $tokens;
    }

    private function normalizeToken(string $token): string
    {
        $length = \strlen($token);

        if ($length >= 2) {
            $first = $token[0];
            $last = $token[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $token = \substr($token, 1, -1);
                $token = \stripcslashes($token);
            }
        }

        return $token;
    }
}

