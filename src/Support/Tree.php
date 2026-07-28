<?php

namespace Chencongbao\Foundation\Support;

final class Tree
{
    public static function build(array $items, string $idKey = 'id', string $parentKey = 'parent_id', string $childrenKey = 'children', int|string|null $rootId = 0): array
    {
        $grouped = [];

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists($idKey, $item)) {
                continue;
            }

            $grouped[$item[$parentKey] ?? $rootId][] = $item;
        }

        $appendChildren = function (int|string|null $parentId) use (&$appendChildren, $grouped, $idKey, $childrenKey): array {
            $children = [];

            foreach ($grouped[$parentId] ?? [] as $item) {
                $item[$childrenKey] = $appendChildren($item[$idKey]);
                $children[] = $item;
            }

            return $children;
        };

        return $appendChildren($rootId);
    }
}
