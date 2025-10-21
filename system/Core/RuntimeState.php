<?php

declare(strict_types=1);

namespace AavionDB\Core;

use DateTimeImmutable;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Storage\BrainRepository;
use AavionDB\Core\Modules\ModuleLoader;

/**
 * Represents the bootstrapped runtime context.
 */
final class RuntimeState
{
    private Container $container;

    private DateTimeImmutable $bootedAt;

    /**
     * @var array<string, mixed>
     */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(Container $container, DateTimeImmutable $bootedAt, array $context = [])
    {
        $this->container = $container;
        $this->bootedAt = $bootedAt;
        $this->context = $context;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function bootedAt(): DateTimeImmutable
    {
        return $this->bootedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Provides diagnostic data for the runtime.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $diagnostics = [
            'version' => $this->context['version'] ?? null,
            'booted_at' => $this->bootedAt->format(DATE_ATOM),
            'options' => $this->context['options'] ?? [],
        ];

        if ($this->container->has(PathLocator::class)) {
            /** @var PathLocator $paths */
            $paths = $this->container->get(PathLocator::class);
            $diagnostics['paths'] = [
                'root' => $paths->root(),
                'system' => $paths->system(),
                'user' => $paths->user(),
                'system_storage' => $paths->systemStorage(),
                'user_storage' => $paths->userStorage(),
            ];
        }

        if ($this->container->has(BrainRepository::class)) {
            /** @var BrainRepository $brains */
            $brains = $this->container->get(BrainRepository::class);
            $diagnostics['brain'] = $brains->integrityReport();
        }

        if ($this->container->has(ModuleLoader::class)) {
            /** @var ModuleLoader $loader */
            $loader = $this->container->get(ModuleLoader::class);
            $diagnostics['modules'] = $loader->diagnostics();
        }

        if ($this->container->has(CommandRegistry::class)) {
            /** @var CommandRegistry $registry */
            $registry = $this->container->get(CommandRegistry::class);
            $diagnostics['commands'] = [
                'count' => \count($registry->all()),
                'items' => $registry->all(),
            ];
        }

        if ($this->container->has(CommandParser::class)) {
            /** @var CommandParser $parser */
            $parser = $this->container->get(CommandParser::class);
            $diagnostics['parser'] = $parser->diagnostics();
        }

        if ($this->container->has(EventBus::class)) {
            /** @var EventBus $bus */
            $bus = $this->container->get(EventBus::class);
            $diagnostics['events'] = [
                'listeners' => $bus->listenerCount(),
            ];
        }

        return $diagnostics;
    }
}
