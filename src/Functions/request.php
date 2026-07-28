<?php

use Chencongbao\Foundation\Contracts\ClientIpResolver;

if (!function_exists('bob_client_ip')) {
    function bob_client_ip(): ?string
    {
        return app(ClientIpResolver::class)->resolve()->ip;
    }
}

if (!function_exists('bob_client_ip_info')) {
    /**
     * @return array<string, bool|string|null>
     */
    function bob_client_ip_info(): array
    {
        return app(ClientIpResolver::class)->resolve()->toArray();
    }
}
