<?php

declare(strict_types=1);

namespace AavionDB\Modules\Schema;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Exceptions\StorageException;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Schema\SchemaException;
use AavionDB\Core\Schema\SchemaValidator;
use AavionDB\Core\Storage\BrainRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_filter;
use function array_map;
use function array_shift;
use function array_unshift;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_last_error;
use function min;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;
use function strtolower;
use function trim;

final class SchemaAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private SchemaValidator $validator;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->validator = new SchemaValidator();
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerListCommand();
        $this->registerShowCommand();
        $this->registerLintCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('schema', function (ParserContext $context): void {
            $tokens = $context->tokens();

            if ($tokens === []) {
                $context->setAction('schema list');
                $this->injectParameters($context, 'schema list', $tokens);
                return;
            }

            $sub = strtolower(trim(array_shift($tokens)));

            switch ($sub) {
                case '':
                case 'list':
                    $context->setAction('schema list');
                    break;
                case 'show':
                    $context->setAction('schema show');
                    break;
                case 'lint':
                case 'validate':
                    $context->setAction('schema lint');
                    break;
                case 'create':
                    $context->setAction('schema create');
                    break;
                case 'update':
                    $context->setAction('schema update');
                    break;
                case 'save':
                    $context->setAction('schema save');
                    break;
                case 'delete':
                case 'remove':
                    $context->setAction('schema delete');
                    break;
                default:
                    array_unshift($tokens, $sub);
                    $context->setAction('schema show');
                    break;
            }

            $this->injectParameters($context, $context->action(), $tokens);
        }, 10);
    }

    private function injectParameters(ParserContext $context, string $action, array $tokens): void
    {
        $parameters = [];

        if (in_array($action, ['schema show', 'schema lint'], true) && $tokens !== []) {
            $parameters['selector'] = array_shift($tokens);
        }

        if (in_array($action, ['schema create', 'schema update', 'schema save'], true) && $tokens !== []) {
            $parameters['slug'] = array_shift($tokens);
        }

        if ($action === 'schema delete' && $tokens !== []) {
            $parameters['slug'] = array_shift($tokens);
            if ($tokens !== []) {
                $parameters['reference'] = array_shift($tokens);
            }
        }

        foreach ($tokens as $token) {
            $normalized = trim($token);
            if ($normalized === '') {
                continue;
            }

            if (str_starts_with($normalized, '--')) {
                $normalized = substr($normalized, 2);
            }

            $key = $normalized;
            $value = true;

            if (strpos($normalized, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $normalized, 2));
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

    private function registerListCommand(): void
    {
        $this->context->commands()->register('schema list', function (array $parameters): CommandResponse {
            return $this->schemaListCommand($parameters);
        }, [
            'description' => 'List available fieldset schemas.',
            'group' => 'schema',
            'usage' => 'schema list [with_versions=1]',
        ]);
    }

    private function registerShowCommand(): void
    {
        $this->context->commands()->register('schema show', function (array $parameters): CommandResponse {
            return $this->schemaShowCommand($parameters);
        }, [
            'description' => 'Show schema payload for a fieldset/version.',
            'group' => 'schema',
            'usage' => 'schema show <fieldset> [@version|#commit]',
        ]);
    }

    private function registerLintCommand(): void
    {
        $this->context->commands()->register('schema lint', function (array $parameters): CommandResponse {
            return $this->schemaLintCommand($parameters);
        }, [
            'description' => 'Validate a JSON schema definition.',
            'group' => 'schema',
            'usage' => 'schema lint {json schema}',
        ]);
    }

    private function registerCreateCommand(): void
    {
        $this->context->commands()->register('schema create', function (array $parameters): CommandResponse {
            return $this->schemaSaveCommand($parameters, 'create');
        }, [
            'description' => 'Create a new fieldset schema.',
            'group' => 'schema',
            'usage' => 'schema create <slug> {json schema}',
        ]);
    }

    private function registerUpdateCommand(): void
    {
        $this->context->commands()->register('schema update', function (array $parameters): CommandResponse {
            return $this->schemaSaveCommand($parameters, 'update');
        }, [
            'description' => 'Update an existing fieldset schema.',
            'group' => 'schema',
            'usage' => 'schema update <slug> {json schema} [--merge=0|1]',
        ]);
    }

    private function registerSaveCommand(): void
    {
        $this->context->commands()->register('schema save', function (array $parameters): CommandResponse {
            return $this->schemaSaveCommand($parameters, 'save');
        }, [
            'description' => 'Upsert a fieldset schema (create if missing, otherwise update).',
            'group' => 'schema',
            'usage' => 'schema save <slug> {json schema} [--merge=0|1]',
        ]);
    }

    private function registerDeleteCommand(): void
    {
        $this->context->commands()->register('schema delete', function (array $parameters): CommandResponse {
            return $this->schemaDeleteCommand($parameters);
        }, [
            'description' => 'Delete a schema entirely or remove a specific revision.',
            'group' => 'schema',
            'usage' => 'schema delete <slug> [@version|#commit]',
        ]);
    }

    private function schemaListCommand(array $parameters): CommandResponse
    {
        $withVersions = $this->toBool($parameters['with_versions'] ?? $parameters['versions'] ?? false);

        try {
            $entities = $this->brains->listEntities('fieldsets');

            if ($withVersions) {
                foreach ($entities as $slug => &$summary) {
                    $summary['versions'] = $this->brains->listEntityVersions('fieldsets', $slug);
                }
                unset($summary);
            } else {
                $entities = array_values($entities);
            }

            $this->context->debug('Schema list generated.', [
                'count' => count($entities),
                'with_versions' => $withVersions,
            ]);

            return CommandResponse::success('schema list', [
                'project' => 'fieldsets',
                'count' => count($entities),
                'schemas' => $entities,
            ], 'Available fieldset schemas.');
        } catch (StorageException $exception) {
            $this->logger->error('Failed to list schemas', [
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema list', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error listing schemas', [
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema list', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schemaShowCommand(array $parameters): CommandResponse
    {
        $selector = $parameters['selector'] ?? $parameters['fieldset'] ?? null;

        if (!is_string($selector) || trim($selector) === '') {
            return CommandResponse::error('schema show', 'Fieldset selector is required.');
        }

        $parsed = $this->parseSelector($selector);
        $fieldset = $parsed['slug'];
        $reference = $parsed['reference'];

        if (isset($parameters['reference']) && is_string($parameters['reference']) && trim($parameters['reference']) !== '') {
            $reference = trim($parameters['reference']);
        }

        if ($fieldset === null || $fieldset === '') {
            return CommandResponse::error('schema show', 'Fieldset slug cannot be empty.');
        }

        try {
            $record = $this->brains->getEntityVersion('fieldsets', $fieldset, $reference);

            return CommandResponse::success('schema show', [
                'project' => 'fieldsets',
                'fieldset' => $fieldset,
                'reference' => $reference,
                'version' => $record['version'] ?? null,
                'record' => $record,
            ], sprintf('Schema "%s"%s.', $fieldset, $reference !== null ? sprintf(' (%s)', $reference) : ''));
        } catch (StorageException $exception) {
            $this->logger->error('Failed to show schema', [
                'fieldset' => $fieldset,
                'reference' => $reference,
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema show', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error showing schema', [
                'fieldset' => $fieldset,
                'reference' => $reference,
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema show', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schemaLintCommand(array $parameters): CommandResponse
    {
        $payload = $parameters['payload'] ?? null;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return CommandResponse::error('schema lint', 'JSON schema payload is required (object).');
        }

        try {
            $this->validator->assertValidSchema($payload);

            return CommandResponse::success('schema lint', [
                'valid' => true,
            ], 'Schema definition is valid.');
        } catch (SchemaException $exception) {
            return CommandResponse::error('schema lint', $exception->getMessage(), [
                'valid' => false,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error linting schema', [
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema lint', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schemaSaveCommand(array $parameters, string $mode): CommandResponse
    {
        $slugRaw = isset($parameters['slug']) ? trim((string) $parameters['slug']) : '';
        if ($slugRaw === '') {
            return CommandResponse::error('schema ' . $mode, 'Parameter "slug" is required.');
        }

        $slug = $this->normaliseSlug($slugRaw);
        $payload = $parameters['payload'] ?? null;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return CommandResponse::error('schema ' . $mode, 'JSON schema payload must be provided as an object.');
        }

        $merge = $this->toBool($parameters['merge'] ?? false);

        try {
            $exists = $this->schemaExists($slug);

            if ($mode === 'create' && $exists) {
                return CommandResponse::error('schema create', sprintf('Schema "%s" already exists.', $slug));
            }

            if ($mode === 'update' && !$exists) {
                return CommandResponse::error('schema update', sprintf('Schema "%s" does not exist.', $slug));
            }

            if ($mode === 'save' && !$exists) {
                $mode = 'create';
            }

            $this->context->debug('Saving fieldset schema.', [
                'slug' => $slug,
                'mode' => $mode,
                'merge' => $merge,
            ]);

            $result = $this->brains->saveEntity('fieldsets', $slug, $payload, [], [
                'merge' => $merge,
                'fieldset_provided' => false,
            ]);

            $message = $mode === 'create'
                ? sprintf('Schema "%s" created (version %s).', $slug, $result['version'] ?? '-')
                : sprintf('Schema "%s" updated (version %s).', $slug, $result['version'] ?? '-');

            return CommandResponse::success('schema ' . $mode, [
                'slug' => $slug,
                'version' => $result['version'] ?? null,
                'commit' => $result['commit'] ?? null,
                'merge' => $merge,
            ], $message);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to save schema', [
                'slug' => $slug,
                'mode' => $mode,
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema ' . $mode, $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    private function schemaDeleteCommand(array $parameters): CommandResponse
    {
        $slugRaw = isset($parameters['slug']) ? trim((string) $parameters['slug']) : '';
        if ($slugRaw === '') {
            return CommandResponse::error('schema delete', 'Parameter "slug" is required.');
        }

        $slug = $this->normaliseSlug($slugRaw);
        $reference = isset($parameters['reference']) ? trim((string) $parameters['reference']) : '';

        try {
            if ($reference !== '') {
                $this->context->debug('Deleting schema version.', [
                    'slug' => $slug,
                    'reference' => $reference,
                ]);
                $deleted = $this->brains->deleteEntityVersion('fieldsets', $slug, $reference);

                return CommandResponse::success('schema delete', [
                    'slug' => $slug,
                    'reference' => $reference,
                    'version' => $deleted['version'] ?? null,
                ], sprintf('Schema version "%s" removed for "%s".', $reference, $slug));
            }

            $this->context->debug('Deleting schema.', [
                'slug' => $slug,
            ]);
            $this->brains->deleteEntity('fieldsets', $slug, true);

            return CommandResponse::success('schema delete', [
                'slug' => $slug,
                'deleted' => true,
            ], sprintf('Schema "%s" deleted.', $slug));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to delete schema', [
                'slug' => $slug,
                'reference' => $reference,
                'exception' => $exception,
            ]);

            return CommandResponse::error('schema delete', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }
    }

    /**
     * @return array{slug: ?string, reference: ?string}
     */
    private function parseSelector(string $value): array
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return [
                'slug' => null,
                'reference' => null,
            ];
        }

        $reference = null;
        $slugPart = $value;

        $positions = array_filter([
            strpos($value, '@'),
            strpos($value, '#'),
        ], static fn ($position) => $position !== false);

        if ($positions !== []) {
            $split = (int) min($positions);
            $slugPart = substr($value, 0, $split);
            $reference = substr($value, $split);
        }

        $slug = trim($slugPart);
        if ($slug === '') {
            return [
                'slug' => null,
                'reference' => null,
            ];
        }

        if ($reference !== null) {
            $reference = trim($reference);
            if ($reference === '') {
                $reference = null;
            }
        }

        return [
            'slug' => $slug,
            'reference' => $reference,
        ];
    }

    private function schemaExists(string $slug): bool
    {
        try {
            $this->brains->listEntityVersions('fieldsets', $slug);

            return true;
        } catch (StorageException $exception) {
            return false;
        }
    }

    private function normaliseSlug(string $slug): string
    {
        $normalized = strtolower($slug);
        $normalized = preg_replace('/[^a-z0-9\-_.]/', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-_.');

        return $normalized === '' ? 'default' : $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
