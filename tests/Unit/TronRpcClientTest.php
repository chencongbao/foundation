<?php

namespace Chencongbao\Foundation\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

class TronRpcClientTest extends TestCase
{
    public function test_it_wraps_every_public_rpc_query_method(): void
    {
        $calls = [];
        $client = $this->client($calls);

        $client->localTransactionsByAddress('TAddress', 'incoming', 1, 30, 'page-cursor', 'TContract');
        $client->transactionsSince(1785290340000, null, 'TContract', 'TAddress', 50);
        $client->events(null, 'event-cursor', 'TContract', null, 80);
        $client->transferEvents(1785290340000, null, null, null, 100);
        $client->paymentExistsAfter([
            'address' => 'TAddress',
            'amount_raw' => '1000000',
            'after_time_ms' => 1785290340000,
        ]);
        $client->addressBalance('TAddress', 'trc20', 'TContract');
        $client->assetSummary('TAddress', 'TContract');

        $this->assertSame([
            'tron.transaction.listLocalByAddress',
            'tron.transaction.listSince',
            'tron.event.list',
            'tron.contract.transferEvents',
            'tron.payment.existsAfter',
            'tron.address.balance',
            'tron.address.assetSummary',
        ], array_column($calls, 'method'));

        $this->assertSame([
            'address' => 'TAddress',
            'direction' => 'incoming',
            'type' => 1,
            'limit' => 30,
            'cursor' => 'page-cursor',
            'contract_address' => 'TContract',
        ], $calls[0]['params']);

        $this->assertSame([
            'since_timestamp_ms' => 1785290340000,
            'contract_address' => 'TContract',
            'address' => 'TAddress',
            'limit' => 50,
        ], $calls[1]['params']);

        $this->assertSame([
            'cursor' => 'event-cursor',
            'contract_address' => 'TContract',
            'limit' => 80,
        ], $calls[2]['params']);
    }

    private function client(array &$calls): TronRpcClient
    {
        $handler = static function (RequestInterface $request, array $_options) use (&$calls) {
            $payload = json_decode((string) $request->getBody(), true);
            $calls[] = $payload;

            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/json',
            ], json_encode([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [],
            ])));
        };

        return new TronRpcClient(
            ['127.0.0.1:9600'],
            'robots',
            str_repeat('s', 32),
            1,
            3,
            new Client(['handler' => $handler])
        );
    }
}
