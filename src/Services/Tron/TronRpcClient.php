<?php

namespace Chencongbao\Foundation\Services\Tron;

use Throwable;
use JsonException;
use GuzzleHttp\Client;
use InvalidArgumentException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Chencongbao\Foundation\Exceptions\TronRpcException;
use Chencongbao\Foundation\Services\Logging\FoundationLogger;

/**
 * TRON 内网 JSON-RPC 客户端。
 *
 * 客户端负责生成 JSON-RPC 请求、对最终发送的原始 JSON 做 HMAC-SHA256 签名，并在
 * 多个 TRON RPC 节点之间轮转。只有连接失败、超时或 HTTP 5xx 才切换节点；鉴权失败、
 * 参数错误、HTTP 429 和正常的业务查询结果不会切换，避免放大请求或绕过共享限流。
 */
class TronRpcClient
{
    private int $endpointCursor;

    /** @var array<int, string> */
    private array $endpoints;

    private ClientInterface $httpClient;
    private string $appId;
    private string $secret;
    private float $connectTimeoutSeconds;
    private float $requestTimeoutSeconds;
    private ?FoundationLogger $logger;

    public function __construct(array $endpoints, string $appId, string $secret, float $connectTimeoutSeconds = 1.0, float $requestTimeoutSeconds = 3.0, ?ClientInterface $httpClient = null, ?FoundationLogger $logger = null)
    {
        $this->appId = $appId;
        $this->secret = $secret;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->requestTimeoutSeconds = $requestTimeoutSeconds;
        $this->logger = $logger;
        $this->endpoints = $this->normalizeEndpoints($endpoints);
        if ($this->endpoints === []) {
            throw new InvalidArgumentException('TRON RPC endpoints 未配置。');
        }
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $this->appId) !== 1) {
            throw new InvalidArgumentException('TRON RPC App ID 格式错误。');
        }
        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException('TRON RPC Secret 必须至少包含 32 个字符。');
        }
        if ($this->connectTimeoutSeconds <= 0 || $this->requestTimeoutSeconds <= 0 || $this->connectTimeoutSeconds > $this->requestTimeoutSeconds) {
            throw new InvalidArgumentException('TRON RPC 超时配置无效，连接超时必须大于 0 且不能超过请求超时。');
        }

        $this->endpointCursor = random_int(0, count($this->endpoints) - 1);
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * 调用任意已公开的 RPC 方法并返回 result。
     *
     * 所有节点均出现可重试故障时抛出 retryable=true 的异常，调用方应进入队列延迟重试；
     * 服务端返回 JSON-RPC error 时保留原始 code、HTTP 状态和 data，且不自动改写业务结果。
     */
    public function call(string $method, array $params = []): mixed
    {
        if ($method === '') {
            throw new InvalidArgumentException('TRON RPC method 不能为空。');
        }

        $id = bin2hex(random_bytes(16));
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lastException = null;
        $this->writeLog('debug', 'RPC 请求开始', [
            'request_id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        // 每次调用从下一个节点开始，在长期运行的 Worker 中均匀使用节点，同时保留单次请求的确定性切换顺序。
        foreach ($this->orderedEndpoints() as $endpoint) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'body' => $body,
                    'connect_timeout' => $this->connectTimeoutSeconds,
                    'timeout' => $this->requestTimeoutSeconds,
                    'http_errors' => false,
                    'headers' => $this->signedHeaders($body),
                ]);
                $status = $response->getStatusCode();
                $this->writeLog('debug', 'RPC 节点响应', [
                    'request_id' => $id,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'http_status' => $status,
                ]);
                $payload = $this->decodeResponse((string) $response->getBody(), $status, $id);
                if ($status >= 500) {
                    $lastException = $this->responseException($payload, $status, true);

                    continue;
                }
                if (isset($payload['error'])) {
                    throw $this->responseException($payload, $status, false);
                }
                if ($status < 200 || $status >= 300) {
                    throw new TronRpcException('TRON RPC 请求失败。', -32097, $status);
                }

                $this->writeLog('info', 'RPC 请求成功', [
                    'request_id' => $id,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'http_status' => $status,
                ]);

                return $payload['result'] ?? null;
            } catch (ConnectException $exception) {
                $lastException = $exception;
                $this->writeLog('warning', 'RPC 节点连接失败', [
                    'request_id' => $id,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'message' => $exception->getMessage(),
                ]);
            } catch (RequestException $exception) {
                if ($exception->getResponse() !== null && $exception->getResponse()->getStatusCode() < 500) {
                    $rpcException = new TronRpcException('TRON RPC 请求失败。', -32097, $exception->getResponse()->getStatusCode(), [], false, $exception);
                    $this->writeException($rpcException, $id, $method, $endpoint);

                    throw $rpcException;
                }
                $lastException = $exception;
                $this->writeLog('warning', 'RPC 节点请求失败', [
                    'request_id' => $id,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'message' => $exception->getMessage(),
                ]);
            } catch (TronRpcException $exception) {
                if (!$exception->retryable()) {
                    $this->writeException($exception, $id, $method, $endpoint);

                    throw $exception;
                }
                $lastException = $exception;
                $this->writeLog('warning', 'RPC 节点返回可重试错误', [
                    'request_id' => $id,
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'rpc_code' => $exception->getCode(),
                    'http_status' => $exception->httpStatus(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $exception = new TronRpcException('所有 TRON RPC 节点当前均不可用。', -32098, 503, [], true, $lastException);
        $this->writeException($exception, $id, $method, null);

        throw $exception;
    }

    public function health(): array
    {
        return (array) $this->call('system.health');
    }

    public function transaction(string $txId): array
    {
        return (array) $this->call('tron.transaction.get', ['tx_id' => $txId]);
    }

    public function chainTransaction(string $txId): array
    {
        return (array) $this->call('tron.transaction.getChainDetail', ['tx_id' => $txId]);
    }

    public function transactionsByAddress(string $address, string $direction = 'all', ?int $type = null, int $limit = 20, ?string $cursor = null, ?string $contractAddress = null): array
    {
        return (array) $this->call('tron.transaction.listByAddress', $this->withoutNulls([
            'address' => $address,
            'direction' => $direction,
            'type' => $type,
            'limit' => $limit,
            'cursor' => $cursor,
            'contract_address' => $contractAddress,
        ]));
    }

    public function localTransactionsByAddress(string $address, string $direction = 'all', ?int $type = null, int $limit = 20, ?string $cursor = null, ?string $contractAddress = null): array
    {
        return (array) $this->call('tron.transaction.listLocalByAddress', $this->withoutNulls([
            'address' => $address,
            'direction' => $direction,
            'type' => $type,
            'limit' => $limit,
            'cursor' => $cursor,
            'contract_address' => $contractAddress,
        ]));
    }

    public function transactionsSince(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100): array
    {
        return (array) $this->call('tron.transaction.listSince', $this->eventParams($sinceTimestampMs, $cursor, $contractAddress, $address, $limit));
    }

    public function events(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100): array
    {
        return (array) $this->call('tron.event.list', $this->eventParams($sinceTimestampMs, $cursor, $contractAddress, $address, $limit));
    }

    public function transferEvents(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100): array
    {
        return (array) $this->call('tron.contract.transferEvents', $this->eventParams($sinceTimestampMs, $cursor, $contractAddress, $address, $limit));
    }

    public function findPayment(array $criteria): array
    {
        return (array) $this->call('tron.payment.find', $criteria);
    }

    public function paymentExistsAfter(array $criteria): array
    {
        return (array) $this->call('tron.payment.existsAfter', $criteria);
    }

    public function addressBalance(string $address, string $asset = 'all', ?string $contractAddress = null): array
    {
        return (array) $this->call('tron.address.balance', $this->withoutNulls([
            'address' => $address,
            'asset' => $asset,
            'contract_address' => $contractAddress,
        ]));
    }

    public function assetSummary(string $address, ?string $contractAddress = null): array
    {
        return (array) $this->call('tron.address.assetSummary', $this->withoutNulls([
            'address' => $address,
            'contract_address' => $contractAddress,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function orderedEndpoints(): array
    {
        $count = count($this->endpoints);
        $start = $this->endpointCursor;
        $this->endpointCursor = ($this->endpointCursor + 1) % $count;
        $ordered = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $ordered[] = $this->endpoints[($start + $offset) % $count];
        }

        return $ordered;
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", ['POST', '/rpc', $this->appId, $timestamp, $nonce, hash('sha256', $body)]);

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Rpc-App-Id' => $this->appId,
            'X-Rpc-Timestamp' => $timestamp,
            'X-Rpc-Nonce' => $nonce,
            'X-Rpc-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    private function decodeResponse(string $body, int $status, string $expectedId): array
    {
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TronRpcException('TRON RPC 返回了无效 JSON。', -32700, $status, [], $status >= 500, $exception);
        }
        if (!is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0' || (string) ($payload['id'] ?? '') !== $expectedId) {
            throw new TronRpcException('TRON RPC 响应结构或请求 ID 无效。', -32603, $status, [], $status >= 500);
        }

        return $payload;
    }

    private function responseException(array $payload, int $status, bool $retryable): TronRpcException
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return new TronRpcException((string) ($error['message'] ?? 'TRON RPC 服务异常。'), (int) ($error['code'] ?? -32603), $status, is_array($error['data'] ?? null) ? $error['data'] : [], $retryable);
    }

    private function eventParams(?int $sinceTimestampMs, ?string $cursor, ?string $contractAddress, ?string $address, int $limit): array
    {
        return $this->withoutNulls([
            'since_timestamp_ms' => $sinceTimestampMs,
            'cursor' => $cursor,
            'contract_address' => $contractAddress,
            'address' => $address,
            'limit' => $limit,
        ]);
    }

    private function withoutNulls(array $params): array
    {
        return array_filter($params, static fn (mixed $value): bool => $value !== null);
    }

    private function writeLog(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->log('tron_rpc', $level, $message, $context);
        } catch (Throwable) {
            // 日志故障不能改变 RPC 调用结果。
        }
    }

    private function writeException(TronRpcException $exception, string $requestId, string $method, ?string $endpoint): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->exception('tron_rpc', $exception, $this->withoutNulls([
                'request_id' => $requestId,
                'method' => $method,
                'endpoint' => $endpoint,
                'rpc_code' => $exception->getCode(),
                'http_status' => $exception->httpStatus(),
                'retryable' => $exception->retryable(),
            ]));
        } catch (Throwable) {
            // 日志或通知故障不能改变 RPC 调用结果。
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeEndpoints(array $endpoints): array
    {
        $normalized = [];
        foreach ($endpoints as $endpoint) {
            $endpoint = trim((string) $endpoint);
            if ($endpoint === '') {
                continue;
            }
            if (!str_contains($endpoint, '://')) {
                $endpoint = 'http://'.$endpoint;
            }
            $endpoint = rtrim($endpoint, '/');
            $normalized[] = str_ends_with($endpoint, '/rpc') ? $endpoint : $endpoint.'/rpc';
        }

        return array_values(array_unique($normalized));
    }
}
