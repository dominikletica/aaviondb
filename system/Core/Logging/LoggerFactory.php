<?php

declare(strict_types=1);

namespace AavionDB\Core\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Factory responsible for creating framework logger instances.
 */
final class LoggerFactory
{
    private string $logDirectory;

    private string $channel;

    private Level $level;

    public function __construct(string $logDirectory, string $channel = 'aaviondb', ?Level $level = null)
    {
        $this->logDirectory = $logDirectory;
        $this->channel = $channel;
        $this->level = $level ?? Level::Debug;
    }

    public function create(): Logger
    {
        if (!\is_dir($this->logDirectory) && !@\mkdir($this->logDirectory, 0775, true) && !\is_dir($this->logDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s".', $this->logDirectory));
        }

        $logFile = $this->logDirectory . DIRECTORY_SEPARATOR . $this->channel . '.log';

        $logger = new Logger($this->channel);
        $logger->pushHandler(new StreamHandler($logFile, $this->level));

        return $logger;
    }
}

