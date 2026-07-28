<?php

namespace Chencongbao\Foundation\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\Enums\ClientIpSource;
use Chencongbao\Foundation\Services\Http\TrustedProxyClientIpResolver;

class TrustedProxyClientIpResolverTest extends TestCase
{
    public function test_it_resolves_cloudflare_ip_from_a_trusted_node(): void
    {
        $result = $this->resolver()->resolve($this->request(
            '104.16.1.2',
            'api.example.com',
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']
        ));

        $this->assertSame('203.0.113.10', $result->ip);
        $this->assertSame(ClientIpSource::Cloudflare, $result->source);
        $this->assertSame('cloudflare', $result->node);
        $this->assertTrue($result->trustedProxy);
    }

    public function test_it_does_not_trust_a_spoofed_header_from_a_direct_request(): void
    {
        $result = $this->resolver()->resolve($this->request(
            '198.51.100.20',
            'api.example.com',
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']
        ));

        $this->assertSame('198.51.100.20', $result->ip);
        $this->assertSame(ClientIpSource::Direct, $result->source);
        $this->assertFalse($result->trustedProxy);
    }

    public function test_it_supports_configured_alibaba_cdn_nodes(): void
    {
        $result = $this->resolver()->resolve($this->request(
            '192.0.2.8',
            'cdn.example.com',
            ['HTTP_ALI_CDN_REAL_IP' => '203.0.113.30']
        ));

        $this->assertSame('203.0.113.30', $result->ip);
        $this->assertSame(ClientIpSource::AlibabaCdn, $result->source);
        $this->assertSame('Ali-Cdn-Real-Ip', $result->header);
    }

    public function test_it_requires_the_configured_domain_to_match(): void
    {
        $result = $this->resolver([
            'nodes' => [
                'cloudflare' => [
                    'domains' => ['api.example.com'],
                ],
            ],
        ])->resolve($this->request(
            '104.16.1.2',
            'untrusted.example.net',
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']
        ));

        $this->assertSame('104.16.1.2', $result->ip);
        $this->assertSame(ClientIpSource::Direct, $result->source);
    }

    public function test_an_empty_node_domain_list_does_not_trust_the_header(): void
    {
        $resolver = new TrustedProxyClientIpResolver([
            'nodes' => [
                'cloudflare' => [
                    'name' => 'Cloudflare',
                    'type' => 'cloudflare',
                    'domains' => [],
                    'headers' => ['CF-Connecting-IP'],
                    'proxies' => ['104.16.0.0/13'],
                ],
            ],
        ]);

        $result = $resolver->resolve($this->request(
            '104.16.1.2',
            'api.example.com',
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']
        ));

        $this->assertSame('104.16.1.2', $result->ip);
        $this->assertSame(ClientIpSource::Direct, $result->source);
    }

    public function test_it_supports_a_custom_waf_using_x_real_ip(): void
    {
        $resolver = new TrustedProxyClientIpResolver([
            'nodes' => [
                'luckypay_waf' => [
                    'name' => 'Luckypay WAF',
                    'type' => 'custom_proxy',
                    'enabled' => true,
                    'domains' => ['pay.example.com'],
                    'headers' => ['X-Real-IP'],
                    'proxies' => ['10.20.0.0/16'],
                ],
            ],
        ]);

        $result = $resolver->resolve($this->request(
            '10.20.1.8',
            'pay.example.com',
            ['HTTP_X_REAL_IP' => '203.0.113.88']
        ));

        $this->assertSame('203.0.113.88', $result->ip);
        $this->assertSame(ClientIpSource::CustomProxy, $result->source);
        $this->assertSame('luckypay_waf', $result->node);
        $this->assertSame('X-Real-IP', $result->header);
    }

    private function resolver(array $overrides = []): TrustedProxyClientIpResolver
    {
        return new TrustedProxyClientIpResolver(array_replace_recursive([
            'nodes' => [
                'cloudflare' => [
                    'name' => 'Cloudflare',
                    'type' => 'cloudflare',
                    'domains' => ['api.example.com', '*.cf.example.com'],
                    'headers' => ['CF-Connecting-IP'],
                    'proxies' => ['104.16.0.0/13'],
                ],
                'alibaba_cdn' => [
                    'name' => 'Alibaba Cloud CDN',
                    'type' => 'alibaba_cdn',
                    'domains' => ['cdn.example.com'],
                    'headers' => ['Ali-Cdn-Real-Ip', 'X-Forwarded-For'],
                    'proxies' => ['192.0.2.0/24'],
                ],
            ],
        ], $overrides));
    }

    private function request(string $remoteAddress, string $host, array $server = []): Request
    {
        return Request::create('/', 'GET', [], [], [], array_merge([
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_HOST' => $host,
        ], $server));
    }
}
