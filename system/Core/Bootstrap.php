<?php

declare(strict_types=1);

namespace AavionDB\Core;

use DateTimeImmutable;
use AavionDB\Core\Exceptions\BootstrapException;
use AavionDB\Core\Exceptions\StorageException;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Storage\BrainRepository;
use Ramsey\Uuid\Uuid;

/**
 * Handles framework bootstrap and service registration.
 */
final class Bootstrap
{
    public const VERSION = '0.1.0-dev';

    /**
     * @var string
     */
    private $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = \rtrim($rootPath, DIRECTORY_SEPARATOR);
    }

    /**
     * Boots the framework and returns the resulting runtime state.
     *
     * @param array<string, mixed> $options
     */
    public function boot(array $options = []): RuntimeState
    {
        try {
            $container = new Container();
            $this->registerBaseServices($container, $options);

            // Ensure required storage structures are present.
            $this->initialiseBrains($container);

            return new RuntimeState(
                $container,
                new DateTimeImmutable(),
                [
                    'version' => self::VERSION,
                    'options' => $options,
                    'root_path' => $this->rootPath,
                ]
            );
        } catch (\Throwable $exception) {
            throw new BootstrapException(
                'Failed to bootstrap AavionDB: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerBaseServices(Container $container, array $options): void
    {
        $container->set(PathLocator::class, function () use ($options): PathLocator {
            $locator = new PathLocator($this->rootPath);
            $locator->ensureDefaultDirectories();

            return $locator;
        });

        $container->set(EventBus::class, static fn (): EventBus => new EventBus());

        $container->set(CommandRegistry::class, static fn (): CommandRegistry => new CommandRegistry());

        $container->set(BrainRepository::class, function (Container $container) use ($options): BrainRepository {
            /** @var PathLocator $paths */
            $paths = $container->get(PathLocator::class);
            /** @var EventBus $events */
            $events = $container->get(EventBus::class);

            $defaults = [
                'active_brain' => $options['active_brain'] ?? 'default',
            ];

            return new BrainRepository($paths, $events, $defaults);
        });
    }

    private function initialiseBrains(Container $container): void
    {
        /** @var BrainRepository $brains */
        $brains = $container->get(BrainRepository::class);

        // Ensure system brain exists.
        $brains->ensureSystemBrain([
            'meta' => [
                'slug' => 'system',
                'uuid' => Uuid::uuid4()->toString(),
            ],
        ]);

        // Ensure the configured active brain exists (lazy create).
        $brains->ensureActiveBrain();
    }
}

