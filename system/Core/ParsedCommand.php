<?php

declare(strict_types=1);

namespace AavionDB\Core;

/**
 * Immutable value object representing a parsed command.
 */
final class ParsedCommand
{
    private string $action;

    /**
     * @var array<string, mixed>
     */
    private array $parameters;

    /**
     * @var array<int, string>
     */
    private array $tokens;

    /**
     * @var mixed
     */
    private $payload;

    private string $rawStatement;

    private string $rawArguments;

    private ?string $rawJson;

    /**
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * @param array<string, mixed> $parameters
     * @param array<int, string>   $tokens
     * @param mixed                $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $action,
        array $parameters,
        array $tokens,
        $payload,
        string $rawStatement,
        string $rawArguments,
        ?string $rawJson,
        array $metadata = []
    ) {
        $this->action = $action;
        $this->parameters = $parameters;
        $this->tokens = $tokens;
        $this->payload = $payload;
        $this->rawStatement = $rawStatement;
        $this->rawArguments = $rawArguments;
        $this->rawJson = $rawJson;
        $this->metadata = $metadata;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<int, string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * @return mixed
     */
    public function payload()
    {
        return $this->payload;
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

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

