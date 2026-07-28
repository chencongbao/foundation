<?php

namespace Chencongbao\Foundation\Exceptions;

use Throwable;
use RuntimeException;

/**
 * 表示 TRON RPC 传输失败、协议错误或服务端返回的业务异常。
 *
 * 调用方可通过 RPC code、HTTP 状态和 data 区分鉴权失败、参数错误、限流与服务异常；
 * 异常内容不会包含 HMAC Secret。retryable 只描述本次错误是否适合由上层队列稍后重试，
 * 客户端内部的多节点切换已经在抛出异常前完成。
 */
class TronRpcException extends RuntimeException
{
    public function __construct(string $message, int $rpcCode = -32098, private readonly int $httpStatus = 0, private readonly array $rpcData = [], private readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, $rpcCode, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function rpcData(): array
    {
        return $this->rpcData;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
