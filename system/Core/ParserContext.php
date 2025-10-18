<?php

declare(strict_types=1);

namespace AavionDB\Core;

/**
 * Mutable context used during command parsing.
 */
final class ParserContext
{
    private string $rawStatement;

    private string $rawArguments;

    private ?string $rawJson;

    private string $action;

    /**
     * @var array<int, string>
     */
    private array $tokens;

    /**
     * @var mixed
     */
    private $payload;

    /**
     * @var array<string, mixed>
     */
    private array $parameters = [];

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    private bool $propagationStopped = false;

    /**
     * @param array<int, string> $tokens
     * @param mixed              $payload
     */
    public function __construct(
        string $rawStatement,
        string $action,
        string $rawArguments,
        ?string $rawJson,
        array $tokens,
        $payload
    ) {
        $this->rawStatement = $rawStatement;
        $this->rawArguments = $rawArguments;
        $this->rawJson = $rawJson;
        $this->action = $action;
        $this->tokens = $tokens;
        $this->payload = $payload;
    }

    public function rawStatement(): string
    {
        return $this->rawStatement;
    }

    public function rawArguments(): string
    {
        return $this->rawArguments;
    }

    public function rawJson(): ?string
    {
        return $this->rawJson;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = \strtolower(\trim($action));
    }

    /**
     * @return array<int, string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * @param array<int, string> $tokens
     */
    public function setTokens(array $tokens): void
    {
        $this->tokens = \array_values($tokens);
    }

    /**
     * @param mixed $payload
     */
    public function setPayload($payload): void
    {
        $this->payload = $payload;
    }

    /**
     * @return mixed
     */
    public function payload()
    {
        return $this->payload;
    }

    public function setRawJson(?string $json): void
    {
        $this->rawJson = $json;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function mergeParameters(array $parameters): void
    {
        $this->parameters = \array_merge($this->parameters, $parameters);
    }

    /**
     * @param mixed $value
     */
    public function setParameter(string $key, $value): void
    {
        $this->parameters[$key] = $value;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function mergeMetadata(array $metadata): void
    {
        $this->metadata = \array_merge($this->metadata, $metadata);
    }

    /**
     * Stops further handler propagation.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function finalize(): ParsedCommand
    {
        $defaults = [
            'tokens' => $this->tokens,
            'payload' => $this->payload,
            'raw' => [
                'statement' => $this->rawStatement,
                'arguments' => $this->rawArguments,
            ],
        ];

        if ($this->rawJson !== null) {
            $defaults['raw']['json'] = $this->rawJson;
        }

        $parameters = \array_merge($defaults, $this->parameters);

        if ($this->metadata !== []) {
            if (isset($parameters['metadata']) && \is_array($parameters['metadata'])) {
                $parameters['metadata'] = \array_merge($this->metadata, $parameters['metadata']);
            } else {
                $parameters['metadata'] = $this->metadata;
            }
        }

        if (!isset($parameters['tokens'])) {
            $parameters['tokens'] = $this->tokens;
        }

        if (!\array_key_exists('payload', $parameters)) {
            $parameters['payload'] = $this->payload;
        }

        if (!isset($parameters['raw'])) {
            $parameters['raw'] = $defaults['raw'];
        }

        return new ParsedCommand(
            $this->action,
            $parameters,
            $this->tokens,
            $this->payload,
            $this->rawStatement,
            $this->rawArguments,
            $this->rawJson,
            $this->metadata
        );
    }
}
