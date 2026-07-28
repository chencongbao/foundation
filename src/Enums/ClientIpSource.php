<?php

namespace Chencongbao\Foundation\Enums;

final class ClientIpSource
{
    public const Cloudflare = 'cloudflare';
    public const AlibabaCdn = 'alibaba_cdn';
    public const CustomProxy = 'custom_proxy';
    public const Direct = 'direct';

    public static function fromNodeType(string $type): string
    {
        return match ($type) {
            self::Cloudflare => self::Cloudflare,
            self::AlibabaCdn => self::AlibabaCdn,
            default => self::CustomProxy,
        };
    }
}
