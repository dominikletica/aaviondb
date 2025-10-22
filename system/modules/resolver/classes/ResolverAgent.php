<?php

declare(strict_types=1);

namespace AavionDB\Modules\Resolver;

use AavionDB\Core\CommandResponse;
use AavionDB\Core\Modules\ModuleContext;
use AavionDB\Core\ParserContext;
use AavionDB\Core\Resolver\ResolverContext;
use AavionDB\Core\Resolver\ResolverEngine;
use AavionDB\Core\Storage\BrainRepository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_key_exists;
use function array_map;
use function array_merge;
use function explode;
use function get_class;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

final class ResolverAgent
{
    private ModuleContext $context;

    private BrainRepository $brains;

    private LoggerInterface $logger;

    private ResolverEngine $resolver;

    public function __construct(ModuleContext $context)
    {
        $this->context = $context;
        $this->brains = $context->brains();
        $this->logger = $context->logger();
        $this->resolver = new ResolverEngine($this->brains, $this->logger);
    }

    public function register(): void
    {
        $this->registerParser();
        $this->registerResolveCommand();
    }

    private function registerParser(): void
    {
        $this->context->commands()->registerParserHandler('resolve', function (ParserContext $context): void {
            $tokens = $context->tokens();
            $parameters = [];
            $shortcodeTokens = [];

            foreach ($tokens as $token) {
                if ($this->looksLikeNamedArgument($token)) {
                    $parameters = array_merge($parameters, $this->tokenToParameter($token));
                    continue;
                }

                $shortcodeTokens[] = $token;
            }

            if ($shortcodeTokens !== []) {
                $parameters['shortcode'] = implode(' ', $shortcodeTokens);
            }

            if ($context->payload() !== null) {
                $parameters['payload'] = $context->payload();
            }

            $context->setAction('resolve');
            $context->mergeParameters($parameters);
            $context->setTokens([]);
        }, 10);
    }

    private function registerResolveCommand(): void
    {
        $this->context->commands()->register('resolve', function (array $parameters): CommandResponse {
            return $this->resolveCommand($parameters);
        }, [
            'description' => 'Resolve a single `[ref]`/`[query]` shortcode within an entity context.',
            'group' => 'resolver',
            'usage' => 'resolve [shortcode] --source=project.entity[@version|#commit] [--param.foo=value]',
        ]);
    }

    private function resolveCommand(array $parameters): CommandResponse
    {
        try {
            $input = $this->normaliseInput($parameters);
            $result = $this->resolveShortcode($input);
        } catch (InvalidArgumentException $exception) {
            return CommandResponse::error('resolve', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to resolve shortcode.', [
                'parameters' => $parameters,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);

            return CommandResponse::error('resolve', $exception->getMessage(), [
                'exception' => [
                    'message' => $exception->getMessage(),
                    'type' => get_class($exception),
                ],
            ]);
        }

        return CommandResponse::success('resolve', $result['payload'], $result['message'], $result['meta']);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function normaliseInput(array $parameters): array
    {
        $shortcode = isset($parameters['shortcode']) ? trim((string) $parameters['shortcode']) : '';
        if ($shortcode === '') {
            throw new InvalidArgumentException('Shortcode is required. Example: resolve [ref @project.entity.field].');
        }

        if (preg_match('/^\[(ref|query)\b/i', $shortcode) !== 1) {
            throw new InvalidArgumentException('Shortcode must start with [ref …] or [query …].');
        }

        $sourceRaw = isset($parameters['source']) ? trim((string) $parameters['source']) : '';
        if ($sourceRaw === '') {
            throw new InvalidArgumentException('Parameter "--source=project.entity[@version|#commit]" is required.');
        }

        $source = $this->parseSource($sourceRaw);
        $params = $this->extractParams($parameters);

        return [
            'shortcode' => $shortcode,
            'source' => $source,
            'params' => $params,
            'raw' => $parameters,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function resolveShortcode(array $input): array
    {
        $project = $input['source']['project'];
        $entity = $input['source']['entity'];
        $reference = $input['source']['reference'];

        $versionRecord = $this->brains->getEntityVersion($project, $entity, $reference);
        $payload = isset($versionRecord['payload']) && is_array($versionRecord['payload'])
            ? $versionRecord['payload']
            : [];

        $report = $this->brains->entityReport($project, $entity, true);
        $pathString = isset($report['path_string']) && is_string($report['path_string'])
            ? trim($report['path_string'])
            : null;

        $context = new ResolverContext(
            $project,
            $entity,
            isset($versionRecord['version']) ? (string) $versionRecord['version'] : null,
            $input['params'],
            $payload,
            $pathString
        );

        $resolved = $this->resolver->resolvePayload(['value' => $input['shortcode']], $context);
        $value = $resolved['value'] ?? null;

        $message = sprintf(
            'Shortcode resolved for %s.%s%s.',
            $project,
            $entity,
            $reference !== null ? sprintf(' (%s)', $reference) : ''
        );

        return [
            'payload' => [
                'shortcode' => $input['shortcode'],
                'resolved' => $value,
                'source' => [
                    'project' => $project,
                    'entity' => $entity,
                    'reference' => $reference,
                    'version' => $versionRecord['version'] ?? null,
                    'commit' => $versionRecord['commit'] ?? null,
                    'path' => $pathString,
                ],
            ],
            'message' => $message,
            'meta' => [
                'project' => $project,
                'entity' => $entity,
                'reference' => $reference,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSource(string $source): array
    {
        $parts = explode('.', $source, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Source must follow the pattern project.entity[@version|#commit].');
        }

        $project = trim($parts[0]);
        $rest = trim($parts[1]);

        if ($project === '' || $rest === '') {
            throw new InvalidArgumentException('Source must include both project and entity slugs.');
        }

        $reference = null;
        $selectorType = null;

        if (preg_match('/(.+)([@#])([A-Za-z0-9_-]+)$/', $rest, $matches) === 1) {
            $rest = trim((string) $matches[1]);
            $selectorType = $matches[2] === '@' ? 'version' : 'commit';
            $reference = trim((string) $matches[3]);
        }

        if ($rest === '') {
            throw new InvalidArgumentException('Entity slug cannot be empty.');
        }

        return [
            'project' => $project,
            'entity' => $rest,
            'reference' => $reference !== null ? $reference : null,
            'selector_type' => $selectorType,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function extractParams(array $parameters): array
    {
        $result = [];

        foreach ($parameters as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $name = null;
            if (str_starts_with($key, 'param.')) {
                $name = substr($key, 6);
            } elseif (str_starts_with($key, 'params.')) {
                $name = substr($key, 7);
            } elseif (str_starts_with($key, 'var.')) {
                $name = substr($key, 4);
            } elseif (str_starts_with($key, 'vars.')) {
                $name = substr($key, 5);
            }

            if ($name === null) {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $result[$name] = $value;
        }

        if (array_key_exists('params', $parameters) && is_array($parameters['params'])) {
            foreach ($parameters['params'] as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $result[trim($name)] = $value;
            }
        }

        if (array_key_exists('vars', $parameters) && is_array($parameters['vars'])) {
            foreach ($parameters['vars'] as $name => $value) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $result[trim($name)] = $value;
            }
        }

        return $result;
    }

    private function looksLikeNamedArgument(string $token): bool
    {
        return str_starts_with($token, '--') || str_contains($token, '=');
    }

    /**
     * @return array<string, string|bool>
     */
    private function tokenToParameter(string $token): array
    {
        if (str_starts_with($token, '--')) {
            $token = substr($token, 2);
        }

        $key = $token;
        $value = true;

        if (str_contains($token, '=')) {
            [$key, $value] = array_map('trim', explode('=', $token, 2));
        }

        if ($key === '') {
            return [];
        }

        return [$key => $value];
    }
}
