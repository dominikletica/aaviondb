<?php

declare(strict_types=1);

namespace AavionDB\Modules\Scheduler;

use AavionDB\AavionDB;
use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_shift;
use function array_unshift;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function microtime;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;
use function substr;
use function strpos;
use function date;

final class SchedulerAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerAddCommand();
        $this->registerEditCommand();
        $this->registerRemoveCommand();
        $this->registerListCommand();
        $this->registerLogCommand();
        $this->registerCronCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('scheduler', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('scheduler list');
                return;
            }

            $sub = strtolower(trim((string) array_shift($tokens)));

            switch ($sub) {
                case 'add':
                    $context->setAction('scheduler add');
                    break;
                case 'edit':
                case 'update':
                    $context->setAction('scheduler edit');
                    break;
                case 'remove':
                case 'delete':
                    $context->setAction('scheduler remove');
                    break;
                case 'list':
                    $context->setAction('scheduler list');
                    break;
                case 'log':
                    $context->setAction('scheduler log');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('scheduler list');
                    break;
            }

            $this->injectParameters($context, $tokens, $context->action());
        }, 10);

        $this->context->commands()->registerParserHandler('cron', function (ParserContext $context): void {
            $context->setAction('cron');
            $context->setTokens([]);
        }, 50);
    }

    private function injectParameters(ParserContext $context, array $tokens, string $action): void
    {
        $parameters = [];

        if ($action === 'scheduler add' || $action === 'scheduler edit') {
            if ($tokens !== []) {
                $first = array_shift($tokens);
                $parameters['slug'] = $first;
            }

            if ($tokens !== []) {
                $parameters['command'] = implode(' ', $tokens);
                $tokens = [];
            }
        } elseif ($action === 'scheduler remove') {
            if ($tokens !== []) {
                $parameters['slug'] = array_shift($tokens);
            }
        }

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
            }

            $key = $token;
            $value = true;

            if (strpos($token, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $token, 2));
            }

            if ($key === '') {
                continue;
            }

            $parameters[$key] = $value;
        }

        if ($context->payload() !== null) {
            $parameters['payload'] = $context->payload();
        }

        $context->mergeParameters($parameters);
        $context->setTokens([]);
    }

    private function registerAddCommand(): void
    {
        $this->context->commands()->register('scheduler add', function (array $parameters): CommandResponse {
            return $this->schedulerAddCommand($parameters);
        });
    }

    private function registerEditCommand(): void
    {
        $this->context->commands()->register('scheduler edit', function (array $parameters): CommandResponse {
            return $this->schedulerEditCommand($parameters);
        });
    }

    private function registerRemoveCommand(): void
    {
        $this->context->commands()->register('scheduler remove', function (array $parameters): CommandResponse {
            return $this->schedulerRemoveCommand($parameters);
        });
    }

    private function registerListCommand(): void
    {
        $this->context->commands()->register('scheduler list', function (array $parameters): CommandResponse {
            return $this->schedulerListCommand();
        });
    }

    private function registerLogCommand(): void
    {
        $this->context->commands()->register('scheduler log', function (array $parameters): CommandResponse {
            return $this->schedulerLogCommand($parameters);
        });
    }

    private function registerCronCommand(): void
    {
        $this->context->commands()->register('cron', function (array $parameters): CommandResponse {
            return $this->cronCommand();
        });
    }

    private function schedulerAddCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? strtolower(trim((string) $parameters['slug'])) : '';
        if ($slug === '') {
            return CommandResponse::error('scheduler add', 'Parameter "slug" is required.');
        }

        $command = $this->extractCommand($parameters);
        if ($command === '') {
            return CommandResponse::error('scheduler add', 'Parameter "command" is required.');
        }

        try {
            $task = $this->brains->createSchedulerTask($slug, $command);

            return CommandResponse::success('scheduler add', $task, sprintf('Scheduler task "%s" added.', $task['slug'] ?? $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to add scheduler task', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('scheduler add', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schedulerEditCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? strtolower(trim((string) $parameters['slug'])) : '';
        if ($slug === '') {
            return CommandResponse::error('scheduler edit', 'Parameter "slug" is required.');
        }

        $command = $this->extractCommand($parameters);
        if ($command === '') {
            return CommandResponse::error('scheduler edit', 'Parameter "command" is required.');
        }

        try {
            $task = $this->brains->updateSchedulerTask($slug, $command);

            return CommandResponse::success('scheduler edit', $task, sprintf('Scheduler task "%s" updated.', $task['slug'] ?? $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to edit scheduler task', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('scheduler edit', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schedulerRemoveCommand(array $parameters): CommandResponse
    {
        $slug = isset($parameters['slug']) ? strtolower(trim((string) $parameters['slug'])) : '';
        if ($slug === '') {
            return CommandResponse::error('scheduler remove', 'Parameter "slug" is required.');
        }

        try {
            $this->brains->deleteSchedulerTask($slug);

            return CommandResponse::success('scheduler remove', [
                'slug' => $slug,
            ], sprintf('Scheduler task "%s" removed.', $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to remove scheduler task', [
                'slug' => $slug,
                'exception' => $exception,
            ]);

            return CommandResponse::error('scheduler remove', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schedulerListCommand(): CommandResponse
    {
        try {
            $tasks = $this->brains->listSchedulerTasks();

            return CommandResponse::success('scheduler list', [
                'count' => count($tasks),
                'tasks' => array_values($tasks),
            ], 'Scheduled commands.');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to list scheduler tasks', [
                'exception' => $exception,
            ]);

            return CommandResponse::error('scheduler list', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schedulerLogCommand(array $parameters): CommandResponse
    {
        $limit = 20;
        if (isset($parameters['limit']) && (is_numeric($parameters['limit']) || is_string($parameters['limit']))) {
            $limit = max(1, (int) $parameters['limit']);
        }

        try {
            $entries = $this->brains->listSchedulerLog($limit);

            return CommandResponse::success('scheduler log', [
                'count' => count($entries),
                'entries' => $entries,
            ], 'Scheduler log entries.');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to load scheduler log', [
                'exception' => $exception,
            ]);

            return CommandResponse::error('scheduler log', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function cronCommand(): CommandResponse
    {
        $tasks = $this->brains->listSchedulerTasks();
        $start = microtime(true);
        $results = [];

        $this->context->debug('Scheduler cron started.', [
            'task_count' => count($tasks),
        ]);

        foreach ($tasks as $task) {
            $slug = $task['slug'] ?? null;
            $command = $task['command'] ?? null;
            if ($slug === null || $command === null) {
                continue;
            }

            $taskStart = microtime(true);
            $status = 'error';
            $message = null;
            $response = null;

            $this->context->debug('Executing scheduler task.', [
                'slug' => $slug,
                'command' => $command,
            ]);

            try {
                $response = AavionDB::command($command);
                $status = strtolower((string) ($response['status'] ?? 'error')) === 'ok' ? 'ok' : 'error';
                $message = $response['message'] ?? null;
            } catch (Throwable $exception) {
                $status = 'error';
                $message = $exception->getMessage();
                if ($this->logger !== null) {
                    $this->logger->error('Scheduler task failed', [
                        'slug' => $slug,
                        'command' => $command,
                        'exception' => $exception,
                    ]);
                }
            }

            $durationMs = (int) ((microtime(true) - $taskStart) * 1000);

            $result = [
                'slug' => $slug,
                'command' => $command,
                'status' => $status,
                'message' => $message,
                'duration_ms' => $durationMs,
            ];

            if (is_array($response)) {
                $result['response'] = $response;
            }

            $results[] = $result;
            $this->brains->updateSchedulerTaskRun($slug, $status, $this->timestamp(), $message);

            $this->context->debug('Scheduler task finished.', [
                'slug' => $slug,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $logEntry = $this->brains->recordSchedulerRun($results, $durationMs);

        $this->context->debug('Scheduler cron completed.', [
            'task_count' => count($tasks),
            'duration_ms' => $durationMs,
        ]);

        return CommandResponse::success('cron', [
            'task_count' => count($tasks),
            'duration_ms' => $durationMs,
            'results' => $results,
            'log_entry' => $logEntry,
        ], 'Scheduler run completed.');
    }

    private function extractCommand(array $parameters): string
    {
        if (isset($parameters['command']) && is_string($parameters['command'])) {
            return trim($parameters['command']);
        }

        if (isset($parameters['payload'])) {
            $payload = $parameters['payload'];
            if (is_string($payload)) {
                return trim($payload);
            }

            if (is_array($payload) && isset($payload['command']) && is_string($payload['command'])) {
                return trim($payload['command']);
            }
        }

        return '';
    }

    private function timestamp(): string
    {
        return date(DATE_ATOM);
    }
}
