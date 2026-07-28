<?php

namespace Chencongbao\Foundation\Facades;

use Illuminate\Support\Facades\Facade;
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

/**
 * @method static mixed call(string $method, array $params = [])
 * @method static array health()
 * @method static array transaction(string $txId)
 * @method static array chainTransaction(string $txId)
 * @method static array transactionsByAddress(string $address, string $direction = 'all', ?int $type = null, int $limit = 20, ?string $cursor = null)
 * @method static array findPayment(array $criteria)
 * @method static array addressBalance(string $address, string $asset = 'all')
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
