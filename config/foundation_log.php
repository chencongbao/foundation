<?php

use Chencongbao\Foundation\Support\ConfigList;

return [
    /*
     * 未单独配置的模块继承 default。channel 必须存在于宿主项目 config/logging.php。
     */
    'default' => [
        'enabled' => (bool) env('FOUNDATION_LOG_ENABLED', false),
        'channel' => (string) env('FOUNDATION_LOG_CHANNEL', 'stack'),
        'driver' => 'single',
        'path' => null,
        'level' => (string) env('FOUNDATION_LOG_LEVEL', 'debug'),
    ],

    /*
     * 可在 foundation_custom.php 中添加任意模块。模块配置只覆盖 default 中的同名字段。
     */
    'modules' => [
        'tron_rpc' => [
            'enabled' => (bool) env('FOUNDATION_LOG_TRON_RPC_ENABLED', false),
            'path' => (string) env('FOUNDATION_LOG_TRON_RPC_PATH', storage_path('logs/{date}/foundation/tron_rpc.log')),
            'level' => (string) env('FOUNDATION_LOG_TRON_RPC_LEVEL', 'debug'),
        ],
        'client_ip' => [
            'enabled' => (bool) env('FOUNDATION_LOG_CLIENT_IP_ENABLED', false),
            'path' => (string) env('FOUNDATION_LOG_CLIENT_IP_PATH', storage_path('logs/{date}/foundation/client_ip.log')),
            'level' => (string) env('FOUNDATION_LOG_CLIENT_IP_LEVEL', 'info'),
        ],
    ],

    /*
     * 未启用或未配置 Bot Token / Chat ID 时，异常通知不会发送。
     */
    'telegram' => [
        'enabled' => (bool) env('FOUNDATION_TELEGRAM_ENABLED', true),
        'bot_token' => (string) env('FOUNDATION_TELEGRAM_BOT_TOKEN', ''),
        'chat_ids' => ConfigList::fromCommaSeparated((string) env('FOUNDATION_TELEGRAM_CHAT_IDS', '')),
        'timeout_seconds' => max(0.5, (float) env('FOUNDATION_TELEGRAM_TIMEOUT', 3)),
        'environment' => (string) env('APP_ENV', 'production'),
        'application' => (string) env('APP_NAME', 'Laravel'),
        'queue' => [
            'enabled' => (bool) env('FOUNDATION_TELEGRAM_QUEUE_ENABLED', true),
            // 留空时跟随宿主项目 queue.default（即 QUEUE_CONNECTION）。
            'connection' => env('FOUNDATION_TELEGRAM_QUEUE_CONNECTION'),
            // 留空时投递到 Laravel 的 default 队列。
            'name' => (string) env('FOUNDATION_TELEGRAM_QUEUE', ''),
            'tries' => max(1, (int) env('FOUNDATION_TELEGRAM_QUEUE_TRIES', 3)),
            'timeout_seconds' => max(1, (int) env('FOUNDATION_TELEGRAM_QUEUE_TIMEOUT', 30)),
            'backoff_seconds' => max(0, (int) env('FOUNDATION_TELEGRAM_QUEUE_BACKOFF', 5)),
        ],
    ],

    'sensitive_keys' => [
        'authorization',
        'password',
        'private_key',
        'secret',
        'signature',
        'token',
    ],
];
