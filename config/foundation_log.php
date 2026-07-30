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
     * 异常日志始终写入，不受全局或模块 enabled 开关影响，也不参与 Telegram 通知去重。
     */
    'exception' => [
        'driver' => 'single',
        'path' => storage_path('logs/{date}/foundation/exception.log'),
        'level' => 'error',
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
        // 留空时使用“[应用名称] 系统异常”；项目可覆盖，并支持 {application} 占位符。
        'exception_title' => '[{application}] 异常告警',
        // 发送失败始终写入独立日志，不受 FOUNDATION_LOG_ENABLED 影响。
        'failure_log' => [
            'driver' => 'single',
            'path' => storage_path('logs/{date}/foundation/telegram.log'),
            'level' => 'error',
        ],
        // 相同模块、异常类和异常内容在此时间内只通知一次；设为 0 可关闭去重。
        'deduplicate_seconds' => 300,
        // 项目可在 foundation_custom.php 中指定参与指纹的 Context 点号路径。
        'deduplicate_context_keys' => [],
        // 项目可配置必须每次通知、不参与去重的异常类。
        'deduplicate_exclude_exceptions' => [],
        'queue' => [
            'enabled' => (bool) env('FOUNDATION_TELEGRAM_QUEUE_ENABLED', true),
            // 留空时跟随宿主项目 queue.default（即 QUEUE_CONNECTION）。
            'connection' => env('FOUNDATION_TELEGRAM_QUEUE_CONNECTION'),
            // 未配置时投递到 notice 队列；环境变量可覆盖队列名。
            'name' => trim((string) env('FOUNDATION_TELEGRAM_QUEUE', 'notice')) ?: 'notice',
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
