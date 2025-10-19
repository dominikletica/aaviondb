<?php

declare(strict_types=1);

namespace AavionDB\Core\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Logger decorator that injects module context metadata into each record.
 */
final class ModuleLogger extends AbstractLogger
{
    private LoggerInterface $logger;

    private string $module;

    private string $source;

    public function __construct(LoggerInterface $logger, string $module)
    {
        $this->logger = $logger;
        $this->module = $module;
        $this->source = sprintf('module:%s', $module);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (!isset($context['module'])) {
            $context['module'] = $this->module;
        }

        if (!isset($context['source'])) {
            $context['source'] = $this->source;
        }

        $this->logger->log($level, $message, $context);
    }
}
