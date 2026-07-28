# 客户端 IP 解析

客户端 IP 功能兼容 Cloudflare、阿里云 CDN、自定义代理和客户端直连。

## 使用方式

原有代码继续使用：

```php
$ip = bob_client_ip();
```

需要查看来源和节点信息时：

```php
$info = bob_client_ip_info();
```

返回字段：

```php
[
    'ip' => '203.0.113.10',
    'source' => 'cloudflare',
    'header' => 'CF-Connecting-IP',
    'remote_address' => '104.16.1.2',
    'host' => 'api.example.com',
    'node' => 'cloudflare',
    'node_name' => 'Cloudflare',
    'trusted_proxy' => true,
]
```

也可以直接注入接口：

```php
use Chencongbao\Foundation\Contracts\ClientIpResolver;

private ClientIpResolver $clientIp;

public function __construct(ClientIpResolver $clientIp)
{
    $this->clientIp = $clientIp;
}

$result = $this->clientIp->resolve();
$result->ip;
$result->source;
$result->toArray();
```

## 按 CDN 配置域名

Cloudflare 和阿里 CDN 分别配置自己的域名：

```dotenv
FOUNDATION_CLOUDFLARE_DOMAINS=api.example.com,*.cf.example.com
FOUNDATION_ALIBABA_CDN_DOMAINS=static.example.com,*.ali.example.com
```

解析器会先根据当前请求 Host 选择节点：

1. Host 命中 `cloudflare.domains`，才执行 Cloudflare 规则；
2. Host 命中 `alibaba_cdn.domains`，才执行阿里 CDN 规则；
3. 未命中任何节点域名，按客户端直连处理。

支持精确域名和 `*.example.com` 通配域名。节点的 `domains` 留空表示不启用该节点；
如果确实需要匹配所有域名，必须明确配置 `['*']`。

## 默认节点

Cloudflare 已内置官方 IPv4 和 IPv6 回源网段，支持通过环境变量追加：

```dotenv
FOUNDATION_CLOUDFLARE_PROXIES=203.0.113.0/24,2001:db8::/32
```

阿里云 CDN 默认识别 `Ali-Cdn-Real-Ip`，并以 `X-Forwarded-For` 作为备用 Header。
阿里云回源节点 IP 动态分配，官方不提供固定公共列表，因此必须根据控制台或 API
查询结果配置：

```dotenv
FOUNDATION_ALIBABA_CDN_PROXIES=192.0.2.10,192.0.2.0/24
```

未配置阿里可信节点时，即使请求带有 `Ali-Cdn-Real-Ip` 也不会信任。

## 自定义节点

在 `client_ip.nodes` 中追加：

```php
'internal_gateway' => [
    'name' => 'Internal Gateway',
    'type' => 'custom_proxy',
    'enabled' => true,
    'domains' => ['api.example.com'],
    'headers' => ['X-Real-IP', 'X-Forwarded-For'],
    'proxies' => ['10.0.0.0/8', '192.168.10.20'],
],
```

节点按照配置顺序匹配。`REMOTE_ADDR` 必须命中 `proxies`，解析器才会读取该节点的
Header，防止客户端伪造 CDN Header。

### 通用 WAF 配置

Foundation 已预留一个 `custom_waf` 节点，适合读取 `X-Real-IP`：

```dotenv
FOUNDATION_WAF_ENABLED=true
FOUNDATION_WAF_NAME=Luckypay WAF
FOUNDATION_WAF_DOMAINS=pay.example.com,admin.example.com
FOUNDATION_WAF_HEADERS=X-Real-IP
FOUNDATION_WAF_PROXIES=203.0.113.10,203.0.113.0/24
```

`custom_waf` 已内置 94 个可信 IPv4、IPv6 节点或网段。`FOUNDATION_WAF_PROXIES` 用于
继续追加后续节点。不能追加 `0.0.0.0/0`，也不能只根据开关直接信任 `X-Real-IP`。

发布独立配置文件：

```bash
php artisan vendor:publish --tag=foundation-client-ip-config
```

发布后配置位于宿主项目的 `config/client_ip.php`。

完整判断顺序：

```text
请求 Host 匹配节点 domains
→ REMOTE_ADDR 匹配该节点 proxies
→ 按该节点 headers 顺序读取有效 IP
→ 返回客户端 IP 和节点来源信息
→ 任一步不满足则继续下一个节点，最终按直连处理
```

Cloudflare 默认网段来源：

- <https://www.cloudflare.com/ips/>

阿里云 CDN 回源节点说明：

- <https://www.alibabacloud.com/help/en/cdn/support/query-ip-addresses-of-pops-for-a-cdn-domain>
