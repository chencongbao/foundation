<?php

namespace Chencongbao\Foundation\Services\Notification;

use GuzzleHttp\ClientInterface;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use JsonException;
use Stringable;

/**
 * 发送项目自定义 Telegram 消息。
 *
 * 与异常通知器分离，可按次覆盖 Bot Token 和 Chat ID；发送失败返回 false，并复用
 * modules.telegram 开启时成功操作写入 telegram.log，失败详情始终写入
 * telegram_failure.log。
 */
final class TelegramMessenger
{
    private const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
    private const PHOTO_MAX_DIMENSION_SUM = 10000;
    private const PHOTO_MAX_RATIO = 20;
    private const PHOTO_CAPTION_MAX_CHARACTERS = 1024;

    private ClientInterface $httpClient;
    private LogManager $logs;
    private array $config;
    private ?string $tokenOverride = null;
    private ?array $chatIdsOverride = null;
    private ?array $replyParametersOverride = null;
    private ?array $replyMarkupOverride = null;
    private ?string $titleOverride = null;
    private bool $withoutAppName = false;

    public function __construct(ClientInterface $httpClient, LogManager $logs, array $config)
    {
        $this->httpClient = $httpClient;
        $this->logs = $logs;
        $this->config = $config;
    }

    /**
     * 返回使用指定 Bot Token 的新实例，不修改容器中的单例。
     */
    public function withToken(string $botToken): self
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            throw new InvalidArgumentException('Telegram Bot Token 不能为空。');
        }

        $messenger = clone $this;
        $messenger->tokenOverride = $botToken;

        return $messenger;
    }

    /**
     * 返回发送给指定接收方的新实例，不修改容器中的单例。
     */
    public function to(array|string $chatIds): self
    {
        $chatIds = $this->normalizeChatIds($chatIds);
        if ($chatIds === []) {
            throw new InvalidArgumentException('Telegram Chat ID 不能为空。');
        }

        $messenger = clone $this;
        $messenger->chatIdsOverride = $chatIds;

        return $messenger;
    }

    /**
     * 返回回复指定 Telegram 消息的新实例，不修改容器中的单例。
     */
    public function replyTo(int $messageId, bool $allowSendingWithoutReply = false): self
    {
        if ($messageId <= 0) {
            throw new InvalidArgumentException('Telegram 回复消息 ID 必须是正整数。');
        }

        $messenger = clone $this;
        $messenger->replyParametersOverride = [
            'message_id' => $messageId,
            'allow_sending_without_reply' => $allowSendingWithoutReply,
        ];

        return $messenger;
    }

    /**
     * 返回携带 Telegram 内联按钮的新实例。
     *
     * 一维按钮数组显示为一行，二维数组可控制多行布局。
     */
    public function withButtons(array $buttons): self
    {
        $messenger = clone $this;
        $messenger->replyMarkupOverride = [
            'inline_keyboard' => $this->normalizeButtonRows($buttons),
        ];

        return $messenger;
    }

    /**
     * 返回使用指定消息标题的新实例。
     */
    public function withTitle(string $title): self
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Telegram 消息标题不能为空。');
        }

        $messenger = clone $this;
        $messenger->titleOverride = $title;

        return $messenger;
    }

    /**
     * JSON 标题不再自动显示应用名称。
     */
    public function withoutAppName(bool $without = true): self
    {
        $messenger = clone $this;
        $messenger->withoutAppName = $without;

        return $messenger;
    }

    public function sendText(string $text): bool
    {
        return $this->sendHtml($this->format($text, 'text'));
    }

    /**
     * 发送调用方已经构造并确认安全的 Telegram HTML。
     */
    public function sendHtml(string $html): bool
    {
        return $this->sender()->send($html);
    }

    /**
     * 将内容格式化为可交给 sendHtml() 的 Telegram HTML。
     *
     * 支持 text、html、code、json。
     */
    public function format(mixed $content, string $format = 'text', array $options = []): string
    {
        $format = strtolower(trim($format));

        return match ($format) {
            'text' => htmlspecialchars(
                $this->stringContent($content),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            'html' => $this->stringContent($content),
            'code' => $this->codeBlock(
                $this->stringContent($content),
                (string) ($options['language'] ?? 'text'),
                $this->resolveTitle($options)
            ),
            'json' => $this->codeBlock(
                $this->jsonContent($content),
                'json',
                $this->jsonTitle($this->resolveTitle($options))
            ),
            default => throw new InvalidArgumentException("不支持的 Telegram 显示格式：{$format}"),
        };
    }

    /**
     * 发送格式化 JSON。标题自动组合为“[APP_NAME] 自定义标题”。
     */
    public function sendJson(mixed $data): bool
    {
        return $this->sendHtml($this->format($data, 'json'));
    }

    /**
     * 使用 HTTP(S) URL 或 Telegram file_id 发送图片。
     */
    public function sendPhoto(string $photo, string $caption = '', array $options = []): bool
    {
        $photo = trim($photo);
        if ($photo === '') {
            throw new InvalidArgumentException('Telegram 图片 URL 或 file_id 不能为空。');
        }
        if (filter_var($photo, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($photo, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Telegram 网络图片只支持 HTTP 或 HTTPS URL。');
            }
        }

        $this->assertPhotoCaption($caption);

        return $this->sender()->sendPhoto($photo, $caption, $this->normalizePhotoOptions($options));
    }

    /**
     * 上传并发送本地图片。
     */
    public function sendPhotoFile(string $path, string $caption = '', array $options = []): bool
    {
        $path = trim($path);
        $realPath = $path === '' ? false : realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new InvalidArgumentException('Telegram 本地图片不存在或不可读。');
        }

        $bytes = filesize($realPath);
        if ($bytes === false || $bytes <= 0 || $bytes > self::PHOTO_MAX_BYTES) {
            throw new InvalidArgumentException('Telegram 本地图片必须大于 0 且不能超过 10 MB。');
        }

        $size = @getimagesize($realPath);
        if (!is_array($size) || !isset($size[0], $size[1]) || $size[0] <= 0 || $size[1] <= 0) {
            throw new InvalidArgumentException('Telegram 本地文件不是可识别的图片。');
        }
        $width = (int) $size[0];
        $height = (int) $size[1];
        if ($width + $height > self::PHOTO_MAX_DIMENSION_SUM) {
            throw new InvalidArgumentException('Telegram 图片宽度与高度之和不能超过 10000。');
        }
        if (max($width, $height) / min($width, $height) > self::PHOTO_MAX_RATIO) {
            throw new InvalidArgumentException('Telegram 图片宽高比不能超过 20。');
        }

        $this->assertPhotoCaption($caption);

        return $this->sender()->sendPhotoFile(
            $realPath,
            $caption,
            $this->normalizePhotoOptions($options)
        );
    }

    /**
     * 设置当前机器人的 Telegram Webhook。
     *
     * 支持 ip_address、max_connections、allowed_updates、drop_pending_updates、
     * secret_token 参数。
     */
    public function setWebhook(string $url, array $options = []): bool
    {
        $url = trim($url);
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new InvalidArgumentException('Telegram Webhook 地址必须是有效的 HTTPS URL。');
        }

        $params = ['url' => $url] + $this->normalizeWebhookOptions($options);

        return $this->sender()->call('setWebhook', $params) === true;
    }

    /**
     * 删除当前机器人的 Telegram Webhook。
     */
    public function removeWebhook(bool $dropPendingUpdates = false): bool
    {
        return $this->sender()->call('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]) === true;
    }

    /**
     * 获取当前机器人的 Telegram Webhook 状态；失败时返回空数组。
     */
    public function getWebhookInfo(): array
    {
        $result = $this->sender()->call('getWebhookInfo');

        return is_array($result) ? $result : [];
    }

    private function codeBlock(string $content, string $language, ?string $title): string
    {
        $language = strtolower(trim($language));
        if (preg_match('/^[a-z0-9_+-]{1,32}$/', $language) !== 1) {
            throw new InvalidArgumentException('Telegram 代码块语言标签格式无效。');
        }

        $message = '';
        if ($title !== null && trim($title) !== '') {
            $message .= '<b>'
                .htmlspecialchars(trim($title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'</b>'
                ."\n";
        }

        return $message
            .'<pre><code class="language-'.$language.'">'
            .htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</code></pre>';
    }

    private function jsonContent(mixed $data): string
    {
        try {
            if (is_string($data)) {
                $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            }

            return json_encode(
                $data,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram JSON 数据格式无效。', 0, $exception);
        }
    }

    private function jsonTitle(?string $title): ?string
    {
        if ($this->withoutAppName) {
            return $title === null || trim($title) === '' ? null : trim($title);
        }

        $application = trim((string) ($this->config['application'] ?? 'Laravel'));
        $fullTitle = '['.($application === '' ? 'Laravel' : $application).']';
        if ($title !== null && trim($title) !== '') {
            $fullTitle .= ' '.trim($title);
        }

        return $fullTitle;
    }

    private function resolveTitle(array $options): ?string
    {
        if (array_key_exists('title', $options)) {
            $title = trim((string) ($options['title'] ?? ''));

            return $title === '' ? null : $title;
        }

        return $this->titleOverride;
    }

    private function stringContent(mixed $content): string
    {
        if (is_string($content) || is_int($content) || is_float($content)) {
            return (string) $content;
        }
        if (is_bool($content)) {
            return $content ? 'true' : 'false';
        }
        if ($content === null) {
            return '';
        }
        if ($content instanceof Stringable) {
            return (string) $content;
        }

        throw new InvalidArgumentException('该 Telegram 显示格式只接受字符串或标量内容。');
    }

    private function sender(): TelegramNotificationSender
    {
        $config = $this->config;
        if ($this->tokenOverride !== null) {
            $config['bot_token'] = $this->tokenOverride;
        }
        if ($this->chatIdsOverride !== null) {
            $config['chat_ids'] = $this->chatIdsOverride;
        }
        if ($this->replyParametersOverride !== null) {
            $config['reply_parameters'] = $this->replyParametersOverride;
        }
        if ($this->replyMarkupOverride !== null) {
            $config['reply_markup'] = $this->replyMarkupOverride;
        }

        return new TelegramNotificationSender($this->httpClient, $config, $this->logs);
    }

    private function normalizePhotoOptions(array $options): array
    {
        $allowed = [
            'show_caption_above_media',
            'has_spoiler',
            'disable_notification',
            'protect_content',
        ];
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                '不支持的 Telegram 图片参数：'.implode(', ', $unknown)
            );
        }

        foreach ($options as $key => $value) {
            if (!is_bool($value)) {
                throw new InvalidArgumentException("Telegram 图片参数 {$key} 必须是布尔值。");
            }
        }

        return $options;
    }

    private function assertPhotoCaption(string $caption): void
    {
        if (preg_match('//u', $caption) !== 1) {
            throw new InvalidArgumentException('Telegram 图片说明必须是有效的 UTF-8。');
        }

        $plainText = html_entity_decode(
            strip_tags($caption),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
        preg_match_all('/./us', $plainText, $characters);
        if (count($characters[0]) > self::PHOTO_CAPTION_MAX_CHARACTERS) {
            throw new InvalidArgumentException('Telegram 图片说明不能超过 1024 个字符。');
        }
    }

    private function normalizeButtonRows(array $buttons): array
    {
        if ($buttons === []) {
            throw new InvalidArgumentException('Telegram 按钮不能为空。');
        }

        $first = reset($buttons);
        $rows = is_array($first) && array_key_exists('text', $first)
            ? [$buttons]
            : $buttons;
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new InvalidArgumentException('Telegram 按钮行不能为空。');
            }

            $normalizedRow = [];
            foreach ($row as $button) {
                $normalizedRow[] = $this->normalizeButton($button);
            }
            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    private function normalizeButton(mixed $button): array
    {
        if (!is_array($button)) {
            throw new InvalidArgumentException('Telegram 按钮必须使用数组定义。');
        }

        $text = trim((string) ($button['text'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Telegram 按钮文字不能为空。');
        }

        $hasUrl = isset($button['url']) && trim((string) $button['url']) !== '';
        $hasCallbackData = isset($button['callback_data'])
            && (string) $button['callback_data'] !== '';
        if ($hasUrl === $hasCallbackData) {
            throw new InvalidArgumentException(
                'Telegram 按钮必须且只能设置 url 或 callback_data 其中一项。'
            );
        }

        if ($hasCallbackData) {
            $callbackData = (string) $button['callback_data'];
            $bytes = strlen($callbackData);
            if ($bytes < 1 || $bytes > 64) {
                throw new InvalidArgumentException('Telegram callback_data 必须为 1 到 64 字节。');
            }

            return [
                'text' => $text,
                'callback_data' => $callbackData,
            ];
        }

        return [
            'text' => $text,
            'url' => trim((string) $button['url']),
        ];
    }

    private function normalizeWebhookOptions(array $options): array
    {
        $allowed = [
            'ip_address',
            'max_connections',
            'allowed_updates',
            'drop_pending_updates',
            'secret_token',
        ];
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                '不支持的 Telegram Webhook 参数：'.implode(', ', $unknown)
            );
        }

        if (isset($options['max_connections'])) {
            $maxConnections = (int) $options['max_connections'];
            if ($maxConnections < 1 || $maxConnections > 100) {
                throw new InvalidArgumentException(
                    'Telegram Webhook max_connections 必须在 1 到 100 之间。'
                );
            }
            $options['max_connections'] = $maxConnections;
        }

        if (array_key_exists('allowed_updates', $options)) {
            if (!is_array($options['allowed_updates'])) {
                throw new InvalidArgumentException(
                    'Telegram Webhook allowed_updates 必须是数组。'
                );
            }
            $options['allowed_updates'] = json_encode(
                array_values($options['allowed_updates']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        if (isset($options['secret_token'])) {
            $secretToken = trim((string) $options['secret_token']);
            if (preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secretToken) !== 1) {
                throw new InvalidArgumentException(
                    'Telegram Webhook secret_token 格式无效。'
                );
            }
            $options['secret_token'] = $secretToken;
        }

        if (array_key_exists('drop_pending_updates', $options)) {
            $options['drop_pending_updates'] = (bool) $options['drop_pending_updates'];
        }
        if (isset($options['ip_address'])) {
            $ipAddress = trim((string) $options['ip_address']);
            if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Telegram Webhook ip_address 格式无效。');
            }
            $options['ip_address'] = $ipAddress;
        }

        return $options;
    }

    private function normalizeChatIds(array|string $chatIds): array
    {
        $values = is_array($chatIds) ? $chatIds : explode(',', $chatIds);
        $normalized = [];
        foreach ($values as $chatId) {
            $chatId = trim((string) $chatId);
            if ($chatId !== '') {
                $normalized[$chatId] = $chatId;
            }
        }

        return array_values($normalized);
    }
}
