<?php

namespace Chencongbao\Foundation\DTOs;

final class ClientIpInfo
{
    public ?string $ip;
    public string $source;
    public ?string $header;
    public ?string $remoteAddress;
    public ?string $host;
    public ?string $node;
    public ?string $nodeName;
    public bool $trustedProxy;

    public function __construct(
        ?string $ip,
        string $source,
        ?string $header,
        ?string $remoteAddress,
        ?string $host,
        ?string $node,
        ?string $nodeName,
        bool $trustedProxy,
    ) {
        $this->ip = $ip;
        $this->source = $source;
        $this->header = $header;
        $this->remoteAddress = $remoteAddress;
        $this->host = $host;
        $this->node = $node;
        $this->nodeName = $nodeName;
        $this->trustedProxy = $trustedProxy;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'source' => $this->source,
            'header' => $this->header,
            'remote_address' => $this->remoteAddress,
            'host' => $this->host,
            'node' => $this->node,
            'node_name' => $this->nodeName,
            'trusted_proxy' => $this->trustedProxy,
        ];
    }
}
