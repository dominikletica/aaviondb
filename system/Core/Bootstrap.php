<?php

declare(strict_types=1);

namespace AavionDB\Core;

use DateTimeImmutable;
use AavionDB\Core\Cache\CacheManager;
use AavionDB\Core\Exceptions\BootstrapException;
use AavionDB\Core\Exceptions\StorageException;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Logging\LoggerFactory;
use AavionDB\Core\Modules\ModuleLoader;
use AavionDB\Core\Security\AuthManager;
use AavionDB\Core\Security\SecurityManager;
use AavionDB\Storage\BrainRepository;
use Monolog\Level;
use Psr\Log\LoggerInterface;
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

    /**
     * @var array<string, mixed>
     */
    private array $config;

    public function __construct(string $rootPath, array $config = [])
    {
        $this->rootPath = \rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->config = $config;
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
            $locator = new PathLocator($this->rootPath, $this->config);
            $locator->ensureDefaultDirectories();

            return $locator;
        });

        $container->set(LoggerInterface::class, function (Container $container) use ($options): LoggerInterface {
            /** @var PathLocator $paths */
            $paths = $container->get(PathLocator::class);

            $level = Level::Debug;
            if (isset($options['log_level']) && \is_string($options['log_level'])) {
                try {
                    $level = Level::fromName(\strtoupper($options['log_level']));
                } catch (\Throwable $exception) {
                    // Fallback to default debug level if invalid input was provided.
                }
            }

            $factory = new LoggerFactory($paths->systemLogs(), 'aaviondb', $level);

            return $factory->create();
        });

        $container->set(EventBus::class, static fn (): EventBus => new EventBus());

        $container->set(CommandParser::class, static function (Container $container): CommandParser {
            /** @var EventBus $events */
            $events = $container->get(EventBus::class);

            return new CommandParser($events);
        });

        $container->set(CommandRegistry::class, function (Container $container): CommandRegistry {
            $registry = new CommandRegistry();

            if ($container->has(CommandParser::class)) {
                $registry->setParser($container->get(CommandParser::class));
            }

            if ($container->has(EventBus::class)) {
                $registry->setEventBus($container->get(EventBus::class));
            }

            if ($container->has(LoggerInterface::class)) {
                $registry->setLogger($container->get(LoggerInterface::class));
            }

            return $registry;
        });

        $container->set(BrainRepository::class, function (Container $container) use ($options): BrainRepository {
            /** @var PathLocator $paths */
            $paths = $container->get(PathLocator::class);
            /** @var EventBus $events */
            $events = $container->get(EventBus::class);
            
            $defaults = [
                'active_brain' => $options['default_brain'] ?? ($options['active_brain'] ?? 'default'),
            ];

            return new BrainRepository($paths, $events, $defaults);
        });

        $container->set(CacheManager::class, function (Container $container): CacheManager {
            /** @var PathLocator $paths */
            $paths = $container->get(PathLocator::class);
            /** @var BrainRepository $brains */
            $brains = $container->get(BrainRepository::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);
            /** @var EventBus $events */
            $events = $container->get(EventBus::class);

            $manager = new CacheManager($paths, $brains, $logger);
            $manager->ensureDefaults();
            $manager->registerEventListeners($events);

            return $manager;
        });

        $container->set(SecurityManager::class, function (Container $container): SecurityManager {
            /** @var BrainRepository $brains */
            $brains = $container->get(BrainRepository::class);
            /** @var CacheManager $cache */
            $cache = $container->get(CacheManager::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            $manager = new SecurityManager($brains, $cache, $logger);
            $manager->ensureDefaults();

            return $manager;
        });

        $container->set(ModuleLoader::class, function (Container $container): ModuleLoader {
            /** @var PathLocator $paths */
            $paths = $container->get(PathLocator::class);
            /** @var CommandRegistry $commands */
            $commands = $container->get(CommandRegistry::class);
            /** @var EventBus $events */
            $events = $container->get(EventBus::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            $loader = new ModuleLoader($paths, $container, $commands, $events, $logger);
            $loader->discover();

            return $loader;
        });

        $container->set(AuthManager::class, function (Container $container) use ($options): AuthManager {
            /** @var BrainRepository $brains */
            $brains = $container->get(BrainRepository::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new AuthManager($brains, $logger, $this->config);
        });

        $container->set('config', function (): array {
            return $this->config;
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

        if ($container->has(ModuleLoader::class)) {
            /** @var ModuleLoader $loader */
            $loader = $container->get(ModuleLoader::class);
            $loader->initialise();
        }
    }
}
