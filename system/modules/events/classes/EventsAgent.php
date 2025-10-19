<?php

declare(strict_types=1);

namespace AavionDB\Modules\Events;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use function array_map;
use function array_values;
use function asort;
use function count;
use function strtolower;
use function str_contains;
use function str_starts_with;
use function trim;

final class EventsAgent
{
    private ModuleContext $context;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerListenersCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('events', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $subcommand = 'listeners';

            if ($tokens !== []) {
                $candidate = strtolower(trim($tokens[0]));
                if ($candidate !== '' && !str_contains($candidate, '=') && !str_starts_with($candidate, '--')) {
                    $subcommand = $candidate;
                    array_shift($tokens);
                }
            }

            $context->setAction('events ' . $subcommand);
            $context->setTokens([]);
        }, 5);
    }

    private function registerListenersCommand(): void
    {
        $this->context->commands()->register('events listeners', function (array $parameters): CommandResponse {
            return $this->listenersCommand();
        }, [
            'description' => 'List registered event listeners grouped by event name.',
            'group' => 'events',
            'usage' => 'events listeners',
        ]);

        $this->context->commands()->register('events', function (array $parameters): CommandResponse {
            return $this->listenersCommand();
        }, [
            'description' => 'Alias for "events listeners".',
            'group' => 'events',
            'usage' => 'events listeners',
        ]);
    }

    private function listenersCommand(): CommandResponse
    {
        $listeners = $this->context->events()->listenerCount();
        asort($listeners);

        $data = array_map(static fn ($event, $count) => [
            'event' => $event,
            'listeners' => $count,
        ], array_keys($listeners), array_values($listeners));

        return CommandResponse::success('events listeners', [
            'count' => count($data),
            'listeners' => $data,
        ], 'Registered event listeners.');
    }
}
