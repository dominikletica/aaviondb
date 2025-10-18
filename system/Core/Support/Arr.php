<?php

declare(strict_types=1);

namespace AavionDB\Core\Support;

/**
 * Array helper utilities.
 */
final class Arr
{
    private function __construct()
    {
    }

    /**
     * Recursively sorts associative array keys while leaving indexed arrays untouched.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function ksortRecursive($value)
    {
        if (\is_array($value)) {
            if (self::isAssoc($value)) {
                \ksort($value);
                foreach ($value as $key => $child) {
                    $value[$key] = self::ksortRecursive($child);
                }

                return $value;
            }

            $normalized = [];
            foreach ($value as $index => $child) {
                $normalized[$index] = self::ksortRecursive($child);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * Determines whether an array is associative.
     */
    public static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return \array_keys($array) !== \range(0, \count($array) - 1);
    }
}

