<?php

namespace Chencongbao\Foundation\Services\Notification;

use Throwable;
use Illuminate\Contracts\Bus\Dispatcher;
use Chencongbao\Foundation\Contracts\ExceptionNotifier;
use Chencongbao\Foundation\Jobs\SendTelegramNotification;

final class TelegramExceptionNotifier implements ExceptionNotifier
{
    private TelegramNotificationSender $sender;
    private Dispatcher $dispatcher;
    private array $config;

    public function __construct(
        TelegramNotificationSender $sender,
        Dispatcher $dispatcher,
        array $config
    )
    {
        $this->sender = $sender;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
    }

    public function notify(string $module, Throwable $exception, array $context = []): bool
    {
        return $this->send($this->exceptionMessage($module, $exception, $context));
    }

    private function send(string $message): bool
    {
        if (!$this->sender->configured()) {
            return false;
        }

        if (!(bool) ($this->config['queue']['enabled'] ?? true)) {
            return $this->sender->send($message);
        }

        try {
            $queue = (array) ($this->config['queue'] ?? []);
            $job = new SendTelegramNotification(
                $message,
                (int) ($queue['tries'] ?? 3),
                (int) ($queue['timeout_seconds'] ?? 30),
                (int) ($queue['backoff_seconds'] ?? 5)
            );
            $connection = trim((string) ($queue['connection'] ?? ''));
            if ($connection !== '') {
                $job->onConnection($connection);
            }
            $queueName = trim((string) ($queue['name'] ?? ''));
            if ($queueName !== '') {
                $job->onQueue($queueName);
            }
            $this->dispatcher->dispatch($job);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function exceptionMessage(string $module, Throwable $exception, array $context): string
    {
        $lines = [
            '['.(string) ($this->config['application'] ?? 'Laravel').'] Foundation exception',
            'Environment: '.(string) ($this->config['environment'] ?? 'unknown'),
            'Module: '.$module,
            'Exception: '.get_class($exception),
            'Message: '.$exception->getMessage(),
            'Code: '.(string) $exception->getCode(),
            'Time: '.date(DATE_ATOM),
        ];
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $lines[] = 'Context: '.($json === false ? '{}' : $json);
        }

        return substr(implode("\n", $lines), 0, 3900);
    }
}
