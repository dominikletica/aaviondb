<?php

declare(strict_types=1);

namespace AavionDB\Core;

/**
 * Minimal synchronous event bus supporting wildcard listeners.
 */
final class EventBus
{
    /**
     * @var array<string, array<int, callable(array<string, mixed>): void>>
     */
    private array $listeners = [];

    /**
     * Registers an event listener.
     *
     * @param callable(array<string, mixed>): void $listener
     */
    public function on(string $event, callable $listener): void
    {
        $event = $this->normalizeEvent($event);
        $this->listeners[$event][] = $listener;
    }

    /**
     * Emits an event synchronously.
     *
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload = []): void
    {
        $event = $this->normalizeEvent($event);

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }

        // Wildcard listeners use trailing asterisk: "system.*"
        foreach ($this->listeners as $pattern => $listeners) {
            if ($pattern !== $event && $this->matchesWildcard($pattern, $event)) {
                foreach ($listeners as $listener) {
                    $listener($payload + ['event' => $event]);
                }
            }
        }
    }

    /**
     * @return array<string, int>
     */
    public function listenerCount(): array
    {
        $counts = [];
        foreach ($this->listeners as $event => $listeners) {
            $counts[$event] = \count($listeners);
        }

        return $counts;
    }

    private function normalizeEvent(string $event): string
    {
        return \strtolower(\trim($event));
    }

    private function matchesWildcard(string $pattern, string $event): bool
    {
        if (!\str_ends_with($pattern, '*')) {
            return false;
        }

        $prefix = \substr($pattern, 0, -1);

        return $prefix !== '' && \str_starts_with($event, $prefix);
    }
}

