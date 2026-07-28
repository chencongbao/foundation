<?php

namespace Chencongbao\Foundation\Support;

use Illuminate\Support\Arr as IlluminateArr;

final class Arr
{
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        return IlluminateArr::get($array, $key, $default);
    }

    public static function only(array $array, array|string $keys): array
    {
        return IlluminateArr::only($array, $keys);
    }

    public static function forget(array &$array, array|string $keys): void
    {
        IlluminateArr::forget($array, $keys);
    }
}
