<?php

namespace Chencongbao\Foundation\Contracts;

use Throwable;

interface ExceptionNotifier
{
    public function notify(string $module, Throwable $exception, array $context = []): bool;
}
