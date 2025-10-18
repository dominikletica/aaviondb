<?php

declare(strict_types=1);

namespace AavionDB\Core\Modules;

/**
 * Immutable metadata descriptor for a discovered module.
 */
final class ModuleDescriptor
{
    public const SCOPE_SYSTEM = 'system';
    public const SCOPE_USER = 'user';

    private string $slug;

    private string $name;

    private string $version;

    private string $scope;

    private string $path;

    /**
     * @var array<string, mixed>
     */
    private array $manifest;

    /**
     * @var array<string, mixed>
     */
    private array $definition;

    /**
     * @var array<int, string>
     */
    private array $dependencies;

    private bool $autoload;

    /**
     * @var callable|null
     */
    private $initializer;

    /**
     * @var array<int, string>
     */
    private array $issues;

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $definition
     * @param array<int, string>   $dependencies
     * @param array<int, string>   $issues
     */
    public function __construct(
        string $slug,
        string $name,
        string $version,
        string $scope,
        string $path,
        array $manifest,
        array $definition,
        array $dependencies,
        bool $autoload,
        ?callable $initializer,
        array $issues = []
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->version = $version;
        $this->scope = $scope;
        $this->path = $path;
        $this->manifest = $manifest;
        $this->definition = $definition;
        $this->dependencies = $dependencies;
        $this->autoload = $autoload;
        $this->initializer = $initializer;
        $this->issues = $issues;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    /**
     * @return array<int, string>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function autoload(): bool
    {
        return $this->autoload;
    }

    /**
     * @return callable|null
     */
    public function initializer(): ?callable
    {
        return $this->initializer;
    }

    /**
     * @return array<int, string>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'scope' => $this->scope,
            'path' => $this->path,
            'dependencies' => $this->dependencies,
            'autoload' => $this->autoload,
            'issues' => $this->issues,
        ];
    }
}

