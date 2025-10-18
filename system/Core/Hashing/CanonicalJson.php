<?php

declare(strict_types=1);

namespace AavionDB\Core\Hashing;

use AavionDB\Core\Support\Arr;

/**
 * Provides deterministic JSON encoding and hashing utilities.
 */
final class CanonicalJson
{
    private function __construct()
    {
    }

    /**
     * Returns canonical JSON for the given value.
     *
     * @param mixed $value
     */
    public static function encode($value): string
    {
        $normalized = Arr::ksortRecursive($value);

        $json = \json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new \JsonException('Failed to encode value as JSON: ' . \json_last_error_msg());
        }

        return $json;
    }

    /**
     * Returns the SHA-256 hash of the canonical JSON representation.
     *
     * @param mixed $value
     */
    public static function hash($value): string
    {
        return \hash('sha256', self::encode($value));
    }
}
