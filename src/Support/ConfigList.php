<?php

namespace Chencongbao\Foundation\Support;

final class ConfigList
{
    /**
     * 将逗号分隔的配置字符串转换为去重后的非空数组。
     *
     * @return array<int, string>
     */
    public static function fromCommaSeparated(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        )));
    }
}
