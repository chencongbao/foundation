<?php

$csv = static fn (string $value): array => array_values(array_filter(array_map(
    'trim',
    explode(',', $value)
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Foundation package configuration
    |--------------------------------------------------------------------------
    |
    | 跨模块的基础配置放在这里；独立服务应使用单独配置文件。
    |
    */

    'client_ip' => [
        /*
         * 节点按顺序匹配。只有 Host 命中节点 domains 且 REMOTE_ADDR 命中 proxies 时，
         * 才会信任对应请求头。可在发布后的配置中追加自建代理节点。
         * domains 留空表示该节点不参与匹配；明确配置 ['*'] 才表示允许所有域名。
         */
        'nodes' => [
            'cloudflare' => [
                'name' => 'Cloudflare',
                'type' => 'cloudflare',
                'enabled' => true,
                'domains' => $csv((string) env('FOUNDATION_CLOUDFLARE_DOMAINS', '')),
                'headers' => ['CF-Connecting-IPv6', 'CF-Connecting-IP'],
                'proxies' => array_values(array_unique(array_merge([
                    '103.21.244.0/22',
                    '103.22.200.0/22',
                    '103.31.4.0/22',
                    '104.16.0.0/13',
                    '104.24.0.0/14',
                    '108.162.192.0/18',
                    '131.0.72.0/22',
                    '141.101.64.0/18',
                    '162.158.0.0/15',
                    '172.64.0.0/13',
                    '173.245.48.0/20',
                    '188.114.96.0/20',
                    '190.93.240.0/20',
                    '197.234.240.0/22',
                    '198.41.128.0/17',
                    '2400:cb00::/32',
                    '2606:4700::/32',
                    '2803:f800::/32',
                    '2405:b500::/32',
                    '2405:8100::/32',
                    '2a06:98c0::/29',
                    '2c0f:f248::/32',
                ], $csv((string) env('FOUNDATION_CLOUDFLARE_PROXIES', ''))))),
            ],

            'alibaba_cdn' => [
                'name' => 'Alibaba Cloud CDN',
                'type' => 'alibaba_cdn',
                'enabled' => true,
                'domains' => $csv((string) env('FOUNDATION_ALIBABA_CDN_DOMAINS', '')),
                'headers' => ['Ali-Cdn-Real-Ip', 'X-Forwarded-For'],
                // 阿里 CDN 回源 IP 动态分配，需按控制台/API 结果补充可信网段。
                'proxies' => $csv((string) env('FOUNDATION_ALIBABA_CDN_PROXIES', '')),
            ],

            'custom_waf' => [
                'name' => (string) env('FOUNDATION_WAF_NAME', 'Custom WAF'),
                'type' => 'custom_proxy',
                'enabled' => (bool) env('FOUNDATION_WAF_ENABLED', false),
                'domains' => $csv((string) env('FOUNDATION_WAF_DOMAINS', '')),
                'headers' => $csv((string) env('FOUNDATION_WAF_HEADERS', 'X-Real-IP')),
                // 必须填写真实 WAF/反向代理回源节点 IP，不能使用 0.0.0.0/0。
                'proxies' => $csv((string) env('FOUNDATION_WAF_PROXIES', '')),
            ],
        ],
    ],
];
