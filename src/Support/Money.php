<?php

namespace Chencongbao\Foundation\Support;

use InvalidArgumentException;

final class Money
{
    public static function format(int|string $minorAmount, int $scale = 2, string $decimalSeparator = '.', string $thousandsSeparator = ','): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('金额小数位不能小于 0。');
        }

        $minorAmount = (string) $minorAmount;
        if (preg_match('/^-?\d+$/', $minorAmount) !== 1) {
            throw new InvalidArgumentException('金额必须是最小货币单位表示的整数。');
        }

        $negative = str_starts_with($minorAmount, '-');
        $digits = ltrim($minorAmount, '-');
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $integer = $scale === 0 ? $digits : substr($digits, 0, -$scale);
        $fraction = $scale === 0 ? '' : substr($digits, -$scale);
        $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousandsSeparator, ltrim($integer, '0')) ?: '0';

        return ($negative ? '-' : '').$formatted.($scale === 0 ? '' : $decimalSeparator.$fraction);
    }
}
