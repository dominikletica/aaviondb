<?php

declare(strict_types=1);

namespace AavionDB\Schema;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function is_object;
use function implode;
use function sprintf;
use function strtolower;

final class SchemaValidator
{
    /**
     * Ensures the provided JSON schema is syntactically valid.
     *
     * @param array<string, mixed> $schema
     */
    public function assertValidSchema(array $schema): void
    {
        $this->validateSchemaNode($schema, '#');
    }

    /**
     * Validates payload against schema and applies defaults for optional fields.
     *
     * @param array<mixed> $payload
     * @param array<string, mixed> $schema
     *
     * @return array<mixed>
     */
    public function applySchema(array $payload, array $schema): array
    {
        $this->validateSchemaNode($schema, '#');

        $result = $this->validateValue($payload, $schema, '#');

        if (!is_array($result)) {
            throw new SchemaException('Schema root must resolve to an array payload.');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateSchemaNode(array $schema, string $path): void
    {
        if (isset($schema['type']) && !$this->validateTypeKeyword($schema['type'])) {
            throw new SchemaException(sprintf('Invalid "type" keyword at %s.', $path));
        }

        if (isset($schema['required']) && !$this->validateRequiredKeyword($schema['required'])) {
            throw new SchemaException(sprintf('Invalid "required" keyword at %s – expected array of strings.', $path));
        }

        if (isset($schema['enum']) && !$this->validateEnumKeyword($schema['enum'])) {
            throw new SchemaException(sprintf('Invalid "enum" keyword at %s – expected non-empty array of scalars.', $path));
        }

        if (isset($schema['properties'])) {
            if (!is_array($schema['properties'])) {
                throw new SchemaException(sprintf('Invalid "properties" keyword at %s – expected object.', $path));
            }

            foreach ($schema['properties'] as $property => $subSchema) {
                if (!is_array($subSchema)) {
                    throw new SchemaException(sprintf('Schema for property "%s" at %s must be an object.', $property, $path));
                }

                $this->validateSchemaNode($subSchema, $path . '/' . $property);
            }
        }

        if (isset($schema['items'])) {
            if (!is_array($schema['items'])) {
                throw new SchemaException(sprintf('Invalid "items" keyword at %s – expected schema object.', $path));
            }

            $this->validateSchemaNode($schema['items'], $path . '/items');
        }
    }

    /**
     * @param mixed $value
     */
    private function validateValue(mixed $value, array $schema, string $path): mixed
    {
        $types = $this->normalizeTypes($schema['type'] ?? null);

        if ($types !== []) {
            if (!$this->valueMatchesTypes($value, $types)) {
                $expected = implode('|', $types);

                throw new SchemaException(sprintf('Type mismatch at %s – expected %s.', $path, $expected));
            }

            if ($value === null) {
                return null;
            }
        }

        if (isset($schema['enum']) && !$this->valueInEnum($value, $schema['enum'])) {
            throw new SchemaException(sprintf('Value at %s not allowed by enum constraint.', $path));
        }

        if (in_array('object', $types, true)) {
            return $this->validateObject($value, $schema, $path);
        }

        if (in_array('array', $types, true)) {
            return $this->validateArray($value, $schema, $path);
        }

        // Primitive value – return as-is.
        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateObject(mixed $value, array $schema, string $path): array
    {
        if (!is_array($value) || $this->isSequentialArray($value)) {
            throw new SchemaException(sprintf('Expected object at %s.', $path));
        }

        $result = $value;
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($properties as $property => $propertySchema) {
            if (!is_array($propertySchema)) {
                continue;
            }

            $propertyPath = $path . '/' . $property;

            if (array_key_exists($property, $result)) {
                $result[$property] = $this->validateValue($result[$property], $propertySchema, $propertyPath);
                continue;
            }

            if (array_key_exists('default', $propertySchema)) {
                $result[$property] = $this->validateValue($propertySchema['default'], $propertySchema, $propertyPath);
                continue;
            }

            if (in_array($property, $required, true)) {
                throw new SchemaException(sprintf('Missing required property "%s" at %s.', $property, $path));
            }

            $placeholder = $this->placeholderForSchema($propertySchema);
            if ($placeholder !== null) {
                $result[$property] = $placeholder;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateArray(mixed $value, array $schema, string $path): array
    {
        if (!is_array($value)) {
            throw new SchemaException(sprintf('Expected array at %s.', $path));
        }

        $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;

        if ($itemSchema === null) {
            return $value;
        }

        $result = [];

        foreach ($value as $index => $item) {
            $result[$index] = $this->validateValue($item, $itemSchema, $path . '/' . $index);
        }

        return $result;
    }

    /**
     * @param mixed $type
     *
     * @return array<int, string>
     */
    private function normalizeTypes(mixed $type): array
    {
        if ($type === null) {
            return [];
        }

        if (is_string($type)) {
            return [$this->normalizeTypeName($type)];
        }

        if (is_array($type)) {
            $types = [];
            foreach ($type as $entry) {
                if (is_string($entry)) {
                    $types[] = $this->normalizeTypeName($entry);
                }
            }

            return array_values(array_unique($types));
        }

        return [];
    }

    private function normalizeTypeName(string $type): string
    {
        return strtolower($type);
    }

    /**
     * @param array<int, string> $types
     */
    private function valueMatchesTypes(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->valueMatchesType($value, $type)) {
                return true;
            }
        }

        return false;
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_array($value) && !$this->isSequentialArray($value),
            default => true,
        };
    }

    private function validateTypeKeyword(mixed $type): bool
    {
        if (is_string($type)) {
            return $type !== '';
        }

        if (is_array($type)) {
            foreach ($type as $entry) {
                if (!is_string($entry) || $entry === '') {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function validateRequiredKeyword(mixed $required): bool
    {
        if (!is_array($required)) {
            return false;
        }

        foreach ($required as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    private function validateEnumKeyword(mixed $enum): bool
    {
        if (!is_array($enum) || $enum === []) {
            return false;
        }

        foreach ($enum as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }

        return true;
    }

    private function valueInEnum(mixed $value, mixed $enum): bool
    {
        if (!is_array($enum)) {
            return true;
        }

        foreach ($enum as $candidate) {
            if ($candidate === $value) {
                return true;
            }

            if (is_numeric($candidate) && is_numeric($value) && (float) $candidate === (float) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function placeholderForSchema(array $schema): mixed
    {
        $types = $this->normalizeTypes($schema['type'] ?? null);

        if ($types === []) {
            return null;
        }

        // Prefer non-null placeholder.
        foreach ($types as $type) {
            $placeholder = $this->placeholderForType($type, $schema);
            if ($placeholder !== null) {
                return $placeholder;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function placeholderForType(string $type, array $schema): mixed
    {
        return match ($type) {
            'null' => null,
            'boolean' => false,
            'integer' => 0,
            'number' => 0,
            'string' => '',
            'array' => [],
            'object' => $this->buildEmptyObject($schema),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function buildEmptyObject(array $schema): array
    {
        $base = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($properties as $property => $propertySchema) {
            if (!is_array($propertySchema)) {
                continue;
            }

            if (array_key_exists('default', $propertySchema)) {
                $base[$property] = $this->validateValue($propertySchema['default'], $propertySchema, '#/' . $property);
                continue;
            }

            $placeholder = $this->placeholderForSchema($propertySchema);
            if ($placeholder !== null) {
                $base[$property] = $placeholder;
            }
        }

        return $base;
    }

    private function isSequentialArray(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
