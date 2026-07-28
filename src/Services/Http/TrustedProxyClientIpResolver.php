<?php

namespace Chencongbao\Foundation\Services\Http;

use Throwable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Chencongbao\Foundation\DTOs\ClientIpInfo;
use Chencongbao\Foundation\Enums\ClientIpSource;
use Chencongbao\Foundation\Contracts\ClientIpResolver;

final class TrustedProxyClientIpResolver implements ClientIpResolver
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function resolve(?Request $request = null): ClientIpInfo
    {
        $request ??= app('request');
        $remoteAddress = $this->validIp($request->server('REMOTE_ADDR'));
        $host = $this->host($request);

        foreach ((array) ($this->config['nodes'] ?? []) as $node => $settings) {
            if (!is_array($settings) || !($settings['enabled'] ?? true)) {
                continue;
            }

            if (
                !$this->hostMatches($host, (array) ($settings['domains'] ?? []))
                || !$this->isTrustedProxy($remoteAddress, (array) ($settings['proxies'] ?? []))
            ) {
                continue;
            }

            foreach ((array) ($settings['headers'] ?? []) as $header) {
                $ip = $this->firstValidIp($request->headers->get((string) $header));
                if ($ip === null) {
                    continue;
                }

                return new ClientIpInfo(
                    $ip,
                    ClientIpSource::fromNodeType((string) ($settings['type'] ?? $node)),
                    (string) $header,
                    $remoteAddress,
                    $host,
                    (string) $node,
                    isset($settings['name']) ? (string) $settings['name'] : null,
                    true,
                );
            }
        }

        return new ClientIpInfo(
            $remoteAddress,
            ClientIpSource::Direct,
            null,
            $remoteAddress,
            $host,
            null,
            null,
            false,
        );
    }

    private function isTrustedProxy(?string $remoteAddress, array $proxies): bool
    {
        if ($remoteAddress === null || $proxies === []) {
            return false;
        }

        try {
            return IpUtils::checkIp($remoteAddress, array_values(array_filter(array_map('strval', $proxies))));
        } catch (Throwable) {
            return false;
        }
    }

    private function firstValidIp(?string $value): ?string
    {
        foreach (explode(',', (string) $value) as $candidate) {
            $ip = $this->validIp(trim($candidate));
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    private function validIp(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private function host(Request $request): ?string
    {
        try {
            $host = strtolower($request->getHost());

            return $host !== '' ? $host : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hostMatches(?string $host, array $domains): bool
    {
        if ($domains === []) {
            return false;
        }

        if ($host === null) {
            return false;
        }

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '*') {
                return true;
            }

            if ($domain === $host) {
                return true;
            }

            if (str_starts_with($domain, '*.') && str_ends_with($host, substr($domain, 1))) {
                return true;
            }
        }

        return false;
    }
}
