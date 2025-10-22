<?php

declare(strict_types=1);

namespace AavionDB\Core\Resolver;

/**
 * Carries context information used while resolving shortcodes inside payloads.
 */
final class ResolverContext
{
    private string $project;

    private string $entity;

    private ?string $version;

    private ?string $path;

    /**
     * @var array<string, mixed>
     */
    private array $params;

    /**
     * @var array<string, mixed>
     */
    private array $payload;

    public function __construct(
        string $project,
        string $entity,
        ?string $version = null,
        array $params = [],
        array $payload = [],
        ?string $path = null
    ) {
        $this->project = $project;
        $this->entity = $entity;
        $this->version = $version;
        $this->params = $params;
        $this->payload = $payload;
        $this->path = $path;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function uid(): string
    {
        return $this->project . '.' . $this->entity;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
