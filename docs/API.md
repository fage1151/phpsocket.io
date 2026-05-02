# PHP Socket.IO v4 服务器 API 文档

基于 Workerman 的高性能 PHP Socket.IO v4 服务器实现。

## 安装

```bash
composer require php-socketio/server
```

## 快速开始

```php
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Socket;

$io = new SocketIOServer('0.0.0.0:8088');

$io->on('connection', function (Socket $socket) {
    echo "客户端连接: {$socket->sid}\n";

    $socket->on('chat message', function (string $msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });

    $socket->on('disconnect', function (string $reason) {
        echo "客户端断开: {$reason}\n";
    });
});

Worker::runAll();
```

---

## Server API (`SocketIOServer`)

### 构造函数

```php
new SocketIOServer(string $listen, array $options = [])
```

- `$listen` - 监听地址，格式 `address:port`，如 `'0.0.0.0:8088'`
- `$options` - 配置选项：
  - `logLevel` - 日志级别（默认 `info`）
  - `cors` - CORS 配置，字符串或数组
  - `pingInterval` - 心跳间隔毫秒（默认 20000）
  - `pingTimeout` - 心跳超时毫秒（默认 25000）
  - `ssl` - SSL 配置数组

### 事件

| 事件 | 参数 | 说明 |
|------|------|------|
| `connection` | `(Socket $socket)` | 新客户端连接 |
| `new_namespace` | `(SocketNamespace $nsp)` | 新命名空间创建 |

### 核心方法

#### `io.on(event, handler)`
注册事件处理器。

```php
$io->on('connection', function (Socket $socket) { ... });
```

#### `io.use(middleware)`
注册全局中间件，签名必须为 `(Socket $socket, callable $next)`。

```php
$io->use(function (Socket $socket, callable $next): void {
    if (!$socket->handshake['auth']['token'] ?? null) {
        $err = new \Exception('Authentication required');
        $err->data = ['content' => 'Please provide a valid token'];
        $next($err);
        return;
    }
    $next();
});
```

#### `io.of(namespace)`
获取或创建命名空间。

```php
$admin = $io->of('/admin');
$admin->use(function (Socket $socket, callable $next): void { ... });
$admin->on('connection', function (Socket $socket) { ... });
```

#### `io.emit(event, ...args)`
向所有连接的客户端广播事件。

```php
$io->emit('notification', '系统维护通知');
```

#### `io.to(room)` / `io.in(room)`
设置广播目标房间（返回 `Broadcaster`）。

```php
$io->to('room1')->emit('hello', 'room1 的成员');
$io->in('room1')->to('room2')->emit('hello', 'room1 和 room2 的成员');
```

#### `io.except(rooms)`
排除指定房间的成员。

```php
$io->to('room1')->except('room2')->emit('hello');
```

#### `io.local()` / `$io->local`
仅在本节点广播（集群模式）。支持方法调用和属性访问两种方式。

```php
$io->local()->emit('hello');
$io->local->emit('hello');
```

#### `io.volatile()` / `$io->volatile`
标记为可丢弃事件（long polling 客户端有未发送队列时丢弃）。支持方法调用和属性访问。

```php
$io->volatile()->emit('position', $x, $y);
$io->volatile->emit('position', $x, $y);
```

#### `io.timeout(ms)`
设置广播确认超时。

```php
$io->timeout(10000)->emit('some-event', function ($err, $responses) { ... });
```

#### `io.compress(bool)` / `$io->compress`
设置是否压缩。

```php
$io->compress(true)->emit('hello');
$io->compress->emit('hello');
```

#### `io.fetchSockets(namespace)`
获取匹配的 Socket 实例数组。

```php
$sockets = $io->fetchSockets('/');
$sockets = $io->in('room1')->fetchSockets();
```

#### `io.socketsJoin(rooms)`
让所有匹配的 Socket 加入房间。

```php
$io->socketsJoin('room1');
$io->in('room1')->socketsJoin(['room2', 'room3']);
```

#### `io.socketsLeave(rooms)`
让所有匹配的 Socket 离开房间。

```php
$io->socketsLeave('room1');
```

#### `io.disconnectSockets(close)`
断开所有匹配的 Socket。

```php
$io->disconnectSockets();
$io->in('room1')->disconnectSockets(true);
```

#### `io.allSockets(namespace)`
获取匹配的 Socket ID 集合。

```php
$sids = $io->allSockets('/');
```

#### `io.serverSideEmit(event, ...args)`
向集群中其他服务器发送消息（需要 Redis/Cluster Adapter）。

```php
$io->serverSideEmit('hello', 'world');
```

#### `io.close(callback)`
优雅关闭服务器。

```php
$io->close(function () {
    echo "服务器已关闭\n";
});
```

#### `io.setAdapter(adapter)`
设置集群适配器。

```php
$io->setAdapter(new RedisAdapter($redisConfig));
```

### 属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `io->sockets` | `SocketNamespace` | 主命名空间别名 |
| `io->engine` | `EngineIOHandler` | Engine.IO 处理器 |
| `io->local` | `Broadcaster` | 本地广播修饰符 |
| `io->volatile` | `Broadcaster` | 可丢弃广播修饰符 |
| `io->compress` | `Broadcaster` | 压缩广播修饰符 |

---

## Socket API

### 属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$socket->sid` | `string` | 会话唯一标识 |
| `$socket->id` | `string` | sid 别名 |
| `$socket->namespace` | `string` | 所属命名空间 |
| `$socket->auth` | `mixed` | 连接时的认证数据 |
| `$socket->handshake` | `array` | 握手信息（headers, address, auth 等） |
| `$socket->data` | `array` | 可共享的任意数据（跨服务器） |
| `$socket->connected` | `bool` | 是否已连接 |
| `$socket->rooms` | `Set` | 所在房间集合 |
| `$socket->broadcast` | `Broadcaster` | 广播器（排除自身） |
| `$socket->nsp` | `SocketNamespace` | 所属命名空间实例 |
| `$socket->conn` | `SocketConn` | 底层连接信息 |
| `$socket->recovered` | `bool` | 是否恢复了连接状态 |

### 事件方法

#### `socket.on(event, callback)`
注册事件监听器。

```php
$socket->on('chat message', function (string $msg) use ($socket) {
    $socket->broadcast->emit('chat message', $msg);
});
```

带确认回调：

```php
$socket->on('ping', function (string $data, callable $ack) {
    $ack('pong');
});
```

#### `socket.once(event, callback)`
注册一次性事件监听器。

#### `socket.off(event, callback)`
移除事件监听器。

#### `socket.emit(event, ...args)`
发送事件到客户端。

```php
$socket->emit('hello', 'world');
$socket->emit('with-ack', $data, function ($response) {
    echo "客户端回复: $response\n";
});
```

#### `socket.emitWithAck(event, ...args)`
发送事件并等待确认。

```php
$socket->emitWithAck('hello', 'world', function ($response) { ... });
```

### 房间方法

#### `socket.join(room)`
加入房间。

```php
$socket->join('room1');
$socket->join(['room1', 'room2']);
```

#### `socket.leave(room)`
离开房间。

```php
$socket->leave('room1');
```

#### `socket.inRoom(room)`
检查是否在指定房间。

```php
if ($socket->inRoom('room1')) { ... }
```

#### `socket.to(room)` / `socket.in(room)`
设置广播目标房间（返回 Broadcaster，排除自身）。

```php
$socket->to('room1')->emit('hello');
```

#### `socket.except(rooms)`
排除指定房间。

```php
$socket->to('room1')->except('room2')->emit('hello');
```

### 广播修饰符

```php
$socket->broadcast->emit('hello');           // 广播给除自己外的所有人
$socket->volatile()->emit('position', $x, $y); // 可丢弃事件
$socket->compress(true)->emit('hello');       // 压缩
$socket->timeout(5000)->emit('hello', $cb);   // 确认超时
```

### 中间件

#### `socket.use(middleware)`
注册 Socket 级别中间件，签名为 `(array $packet, callable $next)`。

`$packet` 格式为 `[eventName, ...data]`，与 JS 版 `[event, ...args]` 一致。

```php
$socket->use(function (array $packet, callable $next): void {
    $eventName = $packet[0] ?? '';
    $allowedEvents = ['chat message', 'message'];
    if (!in_array($eventName, $allowedEvents)) {
        return;
    }
    $next();
});
```

### Catch-all 监听器

```php
$socket->onAny(function (string $event, mixed ...$args) { ... });
$socket->onAnyOutgoing(function (string $event, mixed ...$args) { ... });
$socket->prependAny(function (string $event, mixed ...$args) { ... });
$socket->prependAnyOutgoing(function (string $event, mixed ...$args) { ... });
$socket->offAny($callback);
$socket->offAnyOutgoing($callback);
$socket->listenersAny();
$socket->listenersAnyOutgoing();
```

### 连接控制

#### `socket.disconnect(close)`
断开连接。

```php
$socket->disconnect();       // 断开命名空间
$socket->disconnect(true);   // 关闭底层连接
```

#### `socket.send(...args)`
发送 `message` 事件的快捷方式。

---

## Namespace API (`SocketNamespace`)

### 属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$nsp->name` | `string` | 命名空间名称 |
| `$nsp->sockets` | `array` | 已连接的 Socket 映射（sid => Socket） |
| `$nsp->adapter` | `AdapterInterface` | 适配器实例 |

### 方法

与 Server 级别方法相同：`use()`, `on()`, `off()`, `emit()`, `to()`, `in()`, `except()`, `fetchSockets()`, `allSockets()`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()`, `emitWithAck()`, `local()`, `volatile()`, `compress()`, `timeout()`, `serverSideEmit()` 等。

---

## 中间件系统

### 全局/命名空间中间件

签名：`(Socket $socket, callable $next)`

仅在客户端连接到命名空间时执行。

```php
// 全局中间件
$io->use(function (Socket $socket, callable $next): void {
    echo "连接请求: {$socket->sid}\n";
    $next();
});

// 命名空间中间件
$io->of('/admin')->use(function (Socket $socket, callable $next): void {
    if (!$socket->handshake['auth']['admin'] ?? false) {
        $err = new \Exception('Admin access required');
        $err->data = ['code' => 'FORBIDDEN'];
        $next($err);
        return;
    }
    $next();
});
```

拒绝连接时，客户端会收到 `connect_error` 事件：

```javascript
// 客户端
socket.on("connect_error", (err) => {
    console.log(err.message); // "Admin access required"
    console.log(err.data);    // { code: "FORBIDDEN" }
});
```

### Socket 实例中间件

签名：`(array $packet, callable $next)`

对每个接收的事件包执行。`$packet` 格式为 `[eventName, ...data]`。

```php
$socket->use(function (array $packet, callable $next): void {
    $eventName = $packet[0] ?? '';
    echo "收到事件: {$eventName}\n";
    $next();
});
```

---

## 集群适配器

### Redis 适配器

```php
use PhpSocketIO\Adapter\RedisAdapter;

$adapter = new RedisAdapter([
    'host' => '127.0.0.1',
    'port' => 6379,
]);
$io->setAdapter($adapter);
```

### Cluster 适配器（基于 Workerman Channel）

```php
use PhpSocketIO\Adapter\ClusterAdapter;

$adapter = new ClusterAdapter([
    'channel_ip' => '127.0.0.1',
    'channel_port' => 2206,
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);
```

首先需要启动 Workerman Channel 服务：
```php
// channel_server.php
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

$channel_server = new Worker('Channel://0.0.0.0:2206');
$channel_server->name = 'Socket.IO Cluster Channel';
$channel_server->count = 1;

Worker::runAll();
```

---

## 断开原因

| 原因 | 说明 |
|------|------|
| `server namespace disconnect` | 服务端调用 `socket.disconnect()` |
| `client namespace disconnect` | 客户端调用 `socket.disconnect()` |
| `server shutting down` | 服务端关闭连接 |
| `ping timeout` | 心跳超时 |
| `transport close` | 传输层关闭 |
| `transport error` | 传输层错误 |
| `parse error` | 数据包解析错误 |

---

## 传输层行为

### WebSocket 升级

1. 客户端首次通过 HTTP polling 连接 → 握手 → 开始长轮询
2. 客户端发起 WebSocket 升级请求 → 握手成功 → 立即释放之前的 polling 连接
3. 升级后任何后续的 HTTP polling 请求 → 立即回复 NOOP（`6`），不再 hold

### 直接 WebSocket 连接

客户端也可以直接使用 WebSocket 连接（不经过 polling），此时 `transport` 直接为 `websocket`。

---

## 配置选项

```php
$io = new SocketIOServer('0.0.0.0:8088', [
    'logLevel' => 'debug',
    'pingInterval' => 25000,
    'pingTimeout' => 5000,
    'cors' => [
        'origin' => 'https://example.com',
        'methods' => ['GET', 'POST'],
        'allowedHeaders' => ['Content-Type'],
        'credentials' => true,
    ],
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ],
]);
```

---

## PHP 限制

由于 PHP 没有原生 async/await 支持，以下功能与 JS 版 Socket.IO 存在差异：

### `emitWithAck()` 行为差异

**JS 版本**：返回 Promise，可 await 等待所有客户端响应。

**PHP 版本**：
- `socket->emitWithAck($event, ...$args, $callback)`：发送事件，客户端响应后执行 `$callback`
- `io->emitWithAck($event, ...$args)`：广播事件，立即返回空数组（无法同步等待响应）

```php
// Socket 级别 - 回调会在客户端响应后执行
$socket->emitWithAck('hello', 'world', function ($response) {
    echo "客户端响应: " . json_encode($response) . "\n";
});

// Server 级别 - 立即返回，无法等待响应
$responses = $io->emitWithAck('hello', 'world'); // 返回 []
```

### `serverSideEmitWithAck()` 未实现

此方法依赖 Promise 异步等待，PHP 无法实现。

### 动态命名空间不支持

```php
// JS 版本支持
io.of(/^\/dynamic-\d+$/);

// PHP 版本不支持正则/函数匹配，仅支持字符串
$io->of('/admin');
```

### 连接恢复机制

本实现完整支持 Socket.IO v4 连接状态恢复机制：

- 断开连接时自动保存状态（rooms、data、sent packets）
- 重连时客户端可通过 `pid` 和 `offset` 恢复状态
- 自动恢复 rooms 和 data
- 重发丢失的事件
- `socket.recovered` 属性指示是否成功恢复

```php
$io->on('connection', function ($socket) {
    if ($socket->recovered) {
        echo "连接状态已恢复\n";
        // 可以继续使用之前的数据和房间
    }
});
```

在客户端，启用连接恢复：
```javascript
const io = require('socket.io-client');
const socket = io('http://localhost:8088', {
    reconnection: true,
    reconnectionDelay: 100,
    reconnectionDelayMax: 500,
    reconnectionAttempts: 10
});
```
