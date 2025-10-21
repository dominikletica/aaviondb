<?php

declare(strict_types=1);

namespace AavionDB\Core\Modules;

use AavionDB\AavionDB;
use AavionDB\Core\CommandRegistry;
use AavionDB\Core\Container;
use AavionDB\Core\EventBus;
use AavionDB\Core\Filesystem\PathLocator;
use AavionDB\Core\Cache\CacheManager;
use AavionDB\Core\Security\AuthManager;
use AavionDB\Core\Security\SecurityManager;
use AavionDB\Core\Storage\BrainRepository;
use AavionDB\Core\Logging\ModuleLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provides module initialisers with access to core services.
 */
final class ModuleContext
{
    private ModuleDescriptor $descriptor;

    private Container $container;

    private CommandRegistry $commands;

    private EventBus $events;

    private PathLocator $paths;

    private LoggerInterface $logger;

    private ?LoggerInterface $scopedLogger = null;

    /**
     * @var array<int, string>
     */
    private array $capabilities;

    public function __construct(
        ModuleDescriptor $descriptor,
        Container $container,
        CommandRegistry $commands,
        EventBus $events,
        PathLocator $paths,
        LoggerInterface $logger,
        array $capabilities
    ) {
        $this->descriptor = $descriptor;
        $this->container = $container;
        $this->commands = $commands;
        $this->events = $events;
        $this->paths = $paths;
        $this->logger = $logger;
        $this->capabilities = array_values(array_unique(array_map('strtolower', $capabilities)));
    }

    public function descriptor(): ModuleDescriptor
    {
        return $this->descriptor;
    }

    public function container(): Container
    {
        $this->assertCapability('container.access');

        return $this->container;
    }

    public function commands(): CommandRegistry
    {
        $this->assertCapability('commands.register');

        return $this->commands;
    }

    public function events(): EventBus
    {
        $this->assertCapability('events.dispatch');

        return $this->events;
    }

    public function paths(): PathLocator
    {
        $this->assertCapability('paths.read');

        return $this->paths;
    }

    public function logger(): LoggerInterface
    {
        $this->assertCapability('logger.use');

        if ($this->scopedLogger === null) {
            $this->scopedLogger = new ModuleLogger($this->logger, $this->descriptor->slug());
        }

        return $this->scopedLogger;
    }

    public function brains(): BrainRepository
    {
        $this->assertCapability('storage.read');

        /** @var BrainRepository $repository */
        $repository = $this->container->get(BrainRepository::class);

        return $repository;
    }

    public function cache(): CacheManager
    {
        $this->assertCapability('cache.manage');

        /** @var CacheManager $cache */
        $cache = $this->container->get(CacheManager::class);

        return $cache;
    }

    public function debug(string $message, array $context = []): void
    {
        if (!AavionDB::debugEnabled()) {
            return;
        }

        $context['debug'] = true;
        $this->logger()->debug($message, $context);
    }

    public function auth(): AuthManager
    {
        $this->assertCapability('security.manage');

        /** @var AuthManager $auth */
        $auth = $this->container->get(AuthManager::class);

        return $auth;
    }

    public function security(): SecurityManager
    {
        $this->assertCapability('security.manage');

        /** @var SecurityManager $security */
        $security = $this->container->get(SecurityManager::class);

        return $security;
    }

    public function hasCapability(string $capability): bool
    {
        return \in_array(\strtolower($capability), $this->capabilities, true);
    }

    private function assertCapability(string $capability): void
    {
        if (!$this->hasCapability($capability)) {
            throw new RuntimeException(\sprintf(
                'Module "%s" lacks capability "%s".',
                $this->descriptor->slug(),
                $capability
            ));
        }
    }
}
