# TRON RPC

TRON RPC 实现在 `src/Services/Tron/TronRpcClient.php`，异常位于
`src/Exceptions/TronRpcException.php`，Laravel 静态入口位于
`src/Facades/TronRpc.php`。

包内核心配置是 `config/tron_rpc.php`，支持多个 Endpoint、App ID、HMAC Secret、
连接超时和请求超时。通常直接通过 `.env` 配置；需要覆盖配置结构时，写入宿主项目
`config/foundation_custom.php` 的 `tron_rpc` 顶级键，不发布核心配置文件。

推荐通过构造函数注入：

```php
use Chencongbao\Foundation\Services\Tron\TronRpcClient;

private TronRpcClient $tronRpc;

public function __construct(TronRpcClient $tronRpc)
{
    $this->tronRpc = $tronRpc;
}
```

控制器或命令中也可以使用 `TronRpc` Facade。客户端只在连接失败、超时或 HTTP 5xx
时切换节点。捕获 `TronRpcException` 后，通过 `retryable()` 判断是否应由队列稍后
重试。日志中禁止记录 HMAC Secret。

## 已封装方法

| 客户端方法 | RPC 方法 | 说明 |
| --- | --- | --- |
| `health()` | `system.health` | 服务和数据库健康状态 |
| `transaction()` | `tron.transaction.get` | 本地交易详情 |
| `chainTransaction()` | `tron.transaction.getChainDetail` | 链上原文、回执和本地明细 |
| `transactionsByAddress()` | `tron.transaction.listByAddress` | 地址流水 |
| `localTransactionsByAddress()` | `tron.transaction.listLocalByAddress` | 明确使用本地索引查询地址流水 |
| `transactionsSince()` | `tron.transaction.listSince` | 增量读取 Transfer Event |
| `events()` | `tron.event.list` | 增量事件读取 |
| `transferEvents()` | `tron.contract.transferEvents` | 增量 TRC20 Transfer 读取 |
| `findPayment()` | `tron.payment.find` | 在起止时间内查找付款 |
| `paymentExistsAfter()` | `tron.payment.existsAfter` | 检查指定时间以后是否收到付款 |
| `addressBalance()` | `tron.address.balance` | 查询 TRX、TRC20 或全部余额 |
| `assetSummary()` | `tron.address.assetSummary` | 查询兼容字段和资产数组 |

## 常用调用

```php
use Chencongbao\Foundation\Facades\TronRpc;

$health = TronRpc::health();
$transaction = TronRpc::transaction($txId);
$chainTransaction = TronRpc::chainTransaction($txId);

$transactions = TronRpc::transactionsByAddress(
    $address,
    'incoming',
    1,
    20,
    null,
    $contractAddress
);

$localTransactions = TronRpc::localTransactionsByAddress(
    $address,
    'all',
    null,
    20
);
```

## 增量事件

第一次调用传毫秒时间戳：

```php
$result = TronRpc::events(
    1785290340000,
    null,
    $contractAddress,
    $address,
    100
);
```

后续调用只传服务端返回的 Cursor：

```php
$result = TronRpc::events(
    null,
    $result['cursor'],
    $contractAddress,
    $address,
    100
);
```

`transactionsSince()`、`events()` 和 `transferEvents()` 对应三个服务端协议入口，目前
服务端共享同一套增量查询逻辑。消费端应使用返回的 `event_key` 做幂等；当
`has_more=true` 时立即继续拉取。

## 付款查询

所有金额必须使用字符串，禁止传 `float`：

```php
$payment = TronRpc::findPayment([
    'to_address' => $toAddress,
    'from_address' => $fromAddress,
    'amount_raw' => '1000123',
    'type' => 1,
    'contract_address' => $contractAddress,
    'start_time_ms' => 1785190000000,
    'end_time_ms' => 1785191800000,
]);

$exists = TronRpc::paymentExistsAfter([
    'address' => $toAddress,
    'from_address' => $fromAddress,
    'amount' => '12.345678',
    'after_time_ms' => 1785290340000,
]);
```

`paymentExistsAfter()` 也支持 `amount_raw`。返回的 `ambiguous=true` 表示匹配到多笔，
调用方不能自动认定其中任意一笔属于当前订单。

## 余额与资产

```php
$balance = TronRpc::addressBalance($address, 'all', $contractAddress);
$summary = TronRpc::assetSummary($address, $contractAddress);
```

`assetSummary()` 返回 `trx_balance`、`usdt_balance` 和可扩展的 `assets` 数组。
