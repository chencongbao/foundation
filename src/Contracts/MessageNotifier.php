<?php

namespace Chencongbao\Foundation\Contracts;

interface MessageNotifier
{
    public function notifyMessage(string $module, string $message, array $context = []): bool;
}
