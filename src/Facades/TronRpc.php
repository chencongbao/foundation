<?php

namespace Chencongbao\Foundation\Facades;

use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

/**
 * @method static mixed call(string $method, array $params = [])
 * @method static array health()
 * @method static array transaction(string $txId)
 * @method static array chainTransaction(string $txId)
 * @method static array transactionsByAddress(string $address, string $direction = 'all', ?int $type = null, int $limit = 20, ?string $cursor = null, ?string $contractAddress = null)
 * @method static array localTransactionsByAddress(string $address, string $direction = 'all', ?int $type = null, int $limit = 20, ?string $cursor = null, ?string $contractAddress = null)
 * @method static array transactionsSince(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100)
 * @method static array events(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100)
 * @method static array transferEvents(?int $sinceTimestampMs = null, ?string $cursor = null, ?string $contractAddress = null, ?string $address = null, int $limit = 100)
 * @method static array findPayment(array $criteria)
 * @method static array paymentExistsAfter(array $criteria)
 * @method static array addressBalance(string $address, string $asset = 'all', ?string $contractAddress = null)
 * @method static array assetSummary(string $address, ?string $contractAddress = null)
 *
 * @see TronRpcClient
 */
class TronRpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TronRpcClient::class;
    }
}
