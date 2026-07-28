<?php

namespace Chencongbao\Foundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
}
