<?php

namespace Chencongbao\Foundation\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Chencongbao\Foundation\Services\Notification\TelegramMessenger;

class TelegramMessengerTest extends TestCase
{
    public function test_it_sends_a_code_block_with_custom_bot_and_recipients(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $html = $messenger->format(
            "系统端：商户后台\n用户名：TQ999\n登录IP：20.255.248.158",
            'code',
            ['language' => 'sgpay']
        );
        $sent = $messenger
            ->withToken('999999:custom-token')
            ->to(['-1001', '-1002'])
            ->sendHtml($html);

        $this->assertTrue($sent);
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('/bot999999:custom-token/sendMessage', $requests[0]['uri']);
        $this->assertSame('-1001', $requests[0]['params']['chat_id']);
        $this->assertSame('-1002', $requests[1]['params']['chat_id']);
        $this->assertSame('HTML', $requests[0]['params']['parse_mode']);
        $this->assertStringContainsString(
            '<pre><code class="language-sgpay">系统端：商户后台',
            $requests[0]['params']['text']
        );
    }

    public function test_it_uses_default_credentials_and_escapes_plain_text(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->sendText('<b>not html</b>'));
        $this->assertStringContainsString('/bot123456:default-token/sendMessage', $requests[0]['uri']);
        $this->assertSame('-100-default', $requests[0]['params']['chat_id']);
        $this->assertSame('&lt;b&gt;not html&lt;/b&gt;', $requests[0]['params']['text']);
    }

    public function test_it_accepts_comma_separated_recipients_and_removes_duplicates(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue(
            $messenger->to('-1001, -1002, -1001')->sendHtml('<b>alert</b>')
        );
        $this->assertSame(['-1001', '-1002'], array_column($requests, 'chat_id'));
    }

    public function test_fluent_overrides_do_not_leak_into_the_original_singleton(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $messenger
            ->withToken('999999:custom-token')
            ->to('-100-custom')
            ->sendText('custom');
        $messenger->sendText('default');

        $this->assertStringContainsString('/bot999999:custom-token/sendMessage', $requests[0]['uri']);
        $this->assertSame('-100-custom', $requests[0]['chat_id']);
        $this->assertStringContainsString('/bot123456:default-token/sendMessage', $requests[1]['uri']);
        $this->assertSame('-100-default', $requests[1]['chat_id']);
    }

    public function test_it_replies_to_a_specific_message_without_leaking_to_the_singleton(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->replyTo(9527)->sendText('reply'));
        $this->assertTrue($messenger->sendText('normal'));

        $this->assertSame([
            'message_id' => 9527,
            'allow_sending_without_reply' => false,
        ], json_decode($requests[0]['params']['reply_parameters'], true));
        $this->assertArrayNotHasKey('reply_parameters', $requests[1]['params']);
    }

    public function test_reply_can_be_sent_when_the_original_message_no_longer_exists(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->replyTo(9527, true)->sendText('reply'));

        $replyParameters = json_decode($requests[0]['params']['reply_parameters'], true);
        $this->assertTrue($replyParameters['allow_sending_without_reply']);
    }

    public function test_it_rejects_an_invalid_reply_message_id(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Telegram 回复消息 ID 必须是正整数。');
        $messenger->replyTo(0);
    }

    public function test_it_sends_url_and_callback_buttons_in_one_row(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->withButtons([
            ['text' => '查看详情', 'url' => 'https://example.com/orders/1'],
            ['text' => '确认处理', 'callback_data' => 'order:1:confirm'],
        ])->sendText('订单异常'));

        $this->assertSame([
            'inline_keyboard' => [[
                ['text' => '查看详情', 'url' => 'https://example.com/orders/1'],
                ['text' => '确认处理', 'callback_data' => 'order:1:confirm'],
            ]],
        ], json_decode($requests[0]['params']['reply_markup'], true));
    }

    public function test_it_supports_multiple_button_rows_and_does_not_leak(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $messenger->withButtons([
            [
                ['text' => '通过', 'callback_data' => 'approve'],
                ['text' => '拒绝', 'callback_data' => 'reject'],
            ],
            [
                ['text' => '查看详情', 'url' => 'https://example.com/detail'],
            ],
        ])->sendText('请选择');
        $messenger->sendText('普通消息');

        $markup = json_decode($requests[0]['params']['reply_markup'], true);
        $this->assertCount(2, $markup['inline_keyboard']);
        $this->assertArrayNotHasKey('reply_markup', $requests[1]['params']);
    }

    public function test_it_rejects_a_button_without_exactly_one_action(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('必须且只能设置 url 或 callback_data');
        $messenger->withButtons([
            [
                'text' => '错误按钮',
                'url' => 'https://example.com',
                'callback_data' => 'invalid',
            ],
        ]);
    }

    public function test_it_rejects_callback_data_longer_than_64_bytes(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('callback_data 必须为 1 到 64 字节');
        $messenger->withButtons([
            ['text' => '处理', 'callback_data' => str_repeat('x', 65)],
        ]);
    }

    public function test_it_rejects_an_invalid_code_language(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $messenger->format('message', 'code', ['language' => 'bad language!']);
    }

    public function test_format_supports_text_html_code_and_json(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertSame('&lt;b&gt;text&lt;/b&gt;', $messenger->format('<b>text</b>'));
        $this->assertSame('<b>html</b>', $messenger->format('<b>html</b>', 'html'));
        $this->assertStringContainsString(
            '<code class="language-sgpay">content</code>',
            $messenger->format('content', 'code', ['language' => 'sgpay'])
        );
        $this->assertStringContainsString(
            '&quot;ok&quot;: true',
            $messenger->format(['ok' => true], 'json', ['title' => '接口结果'])
        );
    }

    public function test_it_sends_pretty_json_with_the_application_and_custom_title(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue(
            $messenger->withTitle('接口异常')->sendJson([
                'mid' => 85,
                'error' => '余额查询签名错误',
            ])
        );

        $message = $requests[0]['params']['text'];
        $this->assertStringStartsWith('<b>[Tests] 接口异常</b>', $message);
        $this->assertStringContainsString('<code class="language-json">', $message);
        $this->assertStringContainsString('&quot;mid&quot;: 85', $message);
        $this->assertStringContainsString('余额查询签名错误', $message);
    }

    public function test_it_sends_json_with_a_fluent_title(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->withTitle('接口异常')->sendJson(['ok' => false]));

        $this->assertStringStartsWith(
            '<b>[Tests] 接口异常</b>',
            $requests[0]['params']['text']
        );
    }

    public function test_send_json_only_accepts_the_data_parameter(): void
    {
        $method = new \ReflectionMethod(TelegramMessenger::class, 'sendJson');

        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(1, $method->getNumberOfRequiredParameters());
    }

    public function test_it_can_hide_the_application_name_from_the_json_title(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue(
            $messenger
                ->withTitle('接口异常')
                ->withoutAppName()
                ->sendJson(['ok' => false])
        );

        $message = $requests[0]['params']['text'];
        $this->assertStringStartsWith('<b>接口异常</b>', $message);
        $this->assertStringNotContainsString('[Tests]', $message);
    }

    public function test_title_options_do_not_leak_into_the_original_singleton(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $messenger->withTitle('独立标题')->withoutAppName()->sendJson(['first' => true]);
        $messenger->sendJson(['second' => true]);

        $this->assertStringStartsWith('<b>独立标题</b>', $requests[0]['params']['text']);
        $this->assertStringStartsWith('<b>[Tests]</b>', $requests[1]['params']['text']);
    }

    public function test_it_rejects_an_empty_fluent_title(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Telegram 消息标题不能为空。');
        $messenger->withTitle('  ');
    }

    public function test_it_accepts_and_reformats_a_json_string(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->sendJson('{"ok":true,"count":2}'));
        $this->assertStringStartsWith('<b>[Tests]</b>', $requests[0]['params']['text']);
        $this->assertStringContainsString("\n    &quot;ok&quot;: true,", $requests[0]['params']['text']);
    }

    public function test_it_rejects_an_invalid_json_string(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Telegram JSON 数据格式无效。');
        $messenger->sendJson('{invalid json}');
    }

    public function test_it_sends_a_photo_url_with_caption_reply_buttons_and_options(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $sent = $messenger
            ->replyTo(9527, true)
            ->withButtons([
                ['text' => '查看订单', 'url' => 'https://example.com/orders/1'],
            ])
            ->sendPhoto(
                'https://example.com/order.png',
                '<b>订单异常</b>',
                [
                    'show_caption_above_media' => true,
                    'has_spoiler' => true,
                    'protect_content' => true,
                ]
            );

        $this->assertTrue($sent);
        $this->assertStringContainsString('/sendPhoto', $requests[0]['uri']);
        $this->assertSame('https://example.com/order.png', $requests[0]['params']['photo']);
        $this->assertSame('<b>订单异常</b>', $requests[0]['params']['caption']);
        $this->assertSame('HTML', $requests[0]['params']['parse_mode']);
        $this->assertSame('1', $requests[0]['params']['show_caption_above_media']);
        $this->assertSame('1', $requests[0]['params']['has_spoiler']);
        $this->assertSame('1', $requests[0]['params']['protect_content']);
        $this->assertSame(9527, json_decode(
            $requests[0]['params']['reply_parameters'],
            true
        )['message_id']);
        $this->assertSame('查看订单', json_decode(
            $requests[0]['params']['reply_markup'],
            true
        )['inline_keyboard'][0][0]['text']);
    }

    public function test_it_accepts_a_telegram_photo_file_id(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->sendPhoto('AgACAgQAAxkBAAIBQ2-photo-file-id'));
        $this->assertSame('AgACAgQAAxkBAAIBQ2-photo-file-id', $requests[0]['params']['photo']);
    }

    public function test_it_uploads_a_local_photo_using_multipart_form_data(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);
        $path = sys_get_temp_dir().'/foundation-telegram-photo-'.bin2hex(random_bytes(6)).'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));

        try {
            $this->assertTrue($messenger->sendPhotoFile($path, '<b>本地截图</b>'));
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('/sendPhoto', $requests[0]['uri']);
        $this->assertStringStartsWith('multipart/form-data;', $requests[0]['content_type']);
        $this->assertStringContainsString('name="photo"', $requests[0]['body']);
        $this->assertStringContainsString(basename($path), $requests[0]['body']);
        $this->assertStringContainsString('<b>本地截图</b>', $requests[0]['body']);
    }

    public function test_it_rejects_a_missing_local_photo(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('本地图片不存在或不可读');
        $messenger->sendPhotoFile('/tmp/foundation-missing-photo.png');
    }

    public function test_it_rejects_an_overlong_photo_caption(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('图片说明不能超过 1024 个字符');
        $messenger->sendPhoto('photo-file-id', str_repeat('图', 1025));
    }

    public function test_it_rejects_unknown_photo_options(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持的 Telegram 图片参数');
        $messenger->sendPhoto('photo-file-id', '', ['unknown' => true]);
    }

    public function test_it_sets_a_webhook_with_a_custom_token_and_options(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue(
            $messenger
                ->withToken('999999:webhook-token')
                ->setWebhook('https://example.com/telegram/webhook', [
                    'max_connections' => 20,
                    'allowed_updates' => ['message', 'callback_query'],
                    'drop_pending_updates' => true,
                    'secret_token' => 'webhook_secret-123',
                ])
        );

        $this->assertStringContainsString(
            '/bot999999:webhook-token/setWebhook',
            $requests[0]['uri']
        );
        $this->assertSame('https://example.com/telegram/webhook', $requests[0]['params']['url']);
        $this->assertSame('20', $requests[0]['params']['max_connections']);
        $this->assertSame(
            ['message', 'callback_query'],
            json_decode($requests[0]['params']['allowed_updates'], true)
        );
        $this->assertSame('1', $requests[0]['params']['drop_pending_updates']);
        $this->assertSame('webhook_secret-123', $requests[0]['params']['secret_token']);
    }

    public function test_it_removes_a_webhook_and_can_drop_pending_updates(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertTrue($messenger->removeWebhook(true));
        $this->assertStringContainsString('/deleteWebhook', $requests[0]['uri']);
        $this->assertSame('1', $requests[0]['params']['drop_pending_updates']);
    }

    public function test_it_gets_webhook_information(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->assertSame([
            'url' => 'https://example.com/telegram/webhook',
            'pending_update_count' => 2,
        ], $messenger->getWebhookInfo());
        $this->assertStringContainsString('/getWebhookInfo', $requests[0]['uri']);
    }

    public function test_it_rejects_an_invalid_webhook_url(): void
    {
        $requests = [];
        $messenger = $this->messenger($requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('必须是有效的 HTTPS URL');
        $messenger->setWebhook('http://example.com/webhook');
    }

    private function messenger(array &$requests): TelegramMessenger
    {
        $handler = static function (RequestInterface $request, array $_options) use (&$requests) {
            $body = (string) $request->getBody();
            $contentType = $request->getHeaderLine('Content-Type');
            $params = [];
            if (!str_starts_with($contentType, 'multipart/form-data;')) {
                parse_str($body, $params);
            }
            $requests[] = [
                'uri' => (string) $request->getUri(),
                'chat_id' => $params['chat_id'] ?? null,
                'params' => $params,
                'content_type' => $contentType,
                'body' => $body,
            ];

            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/setWebhook') || str_ends_with($path, '/deleteWebhook')) {
                $body = '{"ok":true,"result":true}';
            } elseif (str_ends_with($path, '/getWebhookInfo')) {
                $body = '{"ok":true,"result":{"url":"https://example.com/telegram/webhook","pending_update_count":2}}';
            } else {
                $body = '{"ok":true,"result":{}}';
            }

            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], $body));
        };

        return new TelegramMessenger(
            new Client(['handler' => $handler]),
            $this->createMock(LogManager::class),
            [
                'enabled' => true,
                'bot_token' => '123456:default-token',
                'chat_ids' => ['-100-default'],
                'timeout_seconds' => 3,
                'application' => 'Tests',
            ]
        );
    }
}
