<?php

namespace Chencongbao\Foundation\Tests;

use Chencongbao\Foundation\FoundationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FoundationServiceProvider::class,
        ];
    }
}
