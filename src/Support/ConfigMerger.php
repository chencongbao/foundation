<?php

namespace Chencongbao\Foundation\Support;

final class ConfigMerger
{
    public static function replaceRecursive(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                isset($defaults[$key])
                && is_array($defaults[$key])
                && is_array($value)
                && !self::isList($defaults[$key])
                && !self::isList($value)
            ) {
                $defaults[$key] = self::replaceRecursive($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    private static function isList(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        return array_keys($values) === range(0, count($values) - 1);
    }
}
