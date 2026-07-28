<?php

namespace Chencongbao\Foundation\Support;

use Illuminate\Support\Str as IlluminateStr;

final class Str
{
    public static function mask(string $value, int $start, int $length, string $character = '*'): string
    {
        return IlluminateStr::mask($value, $character, $start, $length);
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        return IlluminateStr::snake($value, $delimiter);
    }

    public static function random(int $length = 16): string
    {
        return IlluminateStr::random($length);
    }
}
