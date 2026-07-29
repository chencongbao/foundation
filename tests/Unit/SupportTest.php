<?php

namespace Chencongbao\Foundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\Support\ConfigList;
use Chencongbao\Foundation\Support\ConfigMerger;
use Chencongbao\Foundation\Support\Money;
use Chencongbao\Foundation\Support\Tree;

class SupportTest extends TestCase
{
    public function test_it_formats_minor_money_units_without_floating_point_math(): void
    {
        $this->assertSame('1,234.56', Money::format(123456));
        $this->assertSame('-0.05', Money::format(-5));
    }

    public function test_it_builds_a_tree_from_flat_items(): void
    {
        $tree = Tree::build([
            ['id' => 1, 'parent_id' => 0, 'name' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'child'],
        ]);

        $this->assertSame('child', $tree[0]['children'][0]['name']);
    }

    public function test_it_converts_a_comma_separated_config_value_to_a_list(): void
    {
        $this->assertSame(
            ['api.example.com', '*.example.com'],
            ConfigList::fromCommaSeparated(' api.example.com, ,*.example.com,api.example.com ')
        );
    }

    public function test_it_recursively_overrides_maps_and_replaces_lists(): void
    {
        $defaults = [
            'telegram' => [
                'enabled' => true,
                'queue' => [
                    'enabled' => true,
                    'name' => 'foundation-notifications',
                ],
            ],
            'nodes' => [
                'cloudflare' => [
                    'enabled' => false,
                    'domains' => ['*'],
                ],
            ],
        ];

        $merged = ConfigMerger::replaceRecursive($defaults, [
            'telegram' => [
                'queue' => [
                    'name' => 'urgent-notifications',
                ],
            ],
            'nodes' => [
                'cloudflare' => [
                    'enabled' => true,
                    'domains' => ['api.example.com'],
                ],
            ],
        ]);

        $this->assertTrue($merged['telegram']['enabled']);
        $this->assertTrue($merged['telegram']['queue']['enabled']);
        $this->assertSame('urgent-notifications', $merged['telegram']['queue']['name']);
        $this->assertTrue($merged['nodes']['cloudflare']['enabled']);
        $this->assertSame(['api.example.com'], $merged['nodes']['cloudflare']['domains']);
    }
}
