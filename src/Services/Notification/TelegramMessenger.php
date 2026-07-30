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
 * Foundation 的 telegram.log 记录失败详情。
 */
final class TelegramMessenger
{
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
