# Socket.IO PHP服务器使用文档

## 项目概述

Socket.IO PHP服务器是一个基于Workerman的Socket.IO v4兼容服务器，提供实时双向通信功能，支持WebSocket和HTTP轮询两种传输方式。

- **支持Socket.IO v4协议**
- **实时双向通信**
- **支持WebSocket和HTTP轮询**
- **支持房间和命名空间**
- **支持二进制数据传输**
- **支持ACK机制（双向）**
- **支持集群模式**
- **完整的PSR-3日志系统**
- **三层中间件系统（全局、命名空间、Socket实例）**
- **PHP 8.1+ 优化实现**

## 安装步骤

### 1. 克隆项目

```bash
git clone <repository-url>
cd socketio
```

### 2. 安装依赖

```bash
composer install
```

### 3. 启动服务器

```bash
# 启动服务器
php server.php

# 以守护进程方式启动
php server.php -d

# 查看服务器状态
php server.php status

# 停止服务器
php server.php stop
```

## 基本用法

### 1. 服务器配置

在`server.php`文件中配置服务器：

```php
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;

// 创建Socket.IO v4服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // 心跳间隔（毫秒）
    'pingTimeout'  => 20000,  // 心跳超时（毫秒）
    'maxPayload'   => 10485760, // 最大负载（字节）
    'workerCount'  => 1,       // worker数量，默认为1
    'logLevel'     => LogLevel::INFO, // 日志级别
]);
```

#### 配置说明

| 选项 | 类型 | 默认值 | 描述 |
| --- | --- | --- | --- |
| `pingInterval` | int | 25000 | 心跳间隔（毫秒） |
| `pingTimeout` | int | 20000 | 心跳超时（毫秒） |
| `maxPayload` | int | 10485760 | 最大负载大小（字节） |
| `workerCount` | int | 1 | Worker进程数量 |
| `logLevel` | string | LogLevel::INFO | 日志级别（PSR-3） |
| `ssl` | array | [] | SSL配置（用于HTTPS/WSS） |
| `cors` | array/string | null | CORS 跨域配置 |

#### CORS 跨域配置

```php
// 简单配置 - 仅指定允许的源
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => 'https://example.com'
]);

// 完整配置
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => [
        'origin' => 'https://example.com',
        'methods' => ['GET', 'POST', 'OPTIONS'],
        'allowedHeaders' => ['my-custom-header', 'Content-Type'],
        'credentials' => true
    ]
]);
```

#### CORS 配置选项

| 选项 | 类型 | 默认值 | 描述 |
| --- | --- | --- | --- |
| `origin` | string/array | `*` | 允许的源 |
| `methods` | array | `['GET', 'POST', 'OPTIONS']` | 允许的 HTTP 方法 |
| `allowedHeaders` | array | `['Content-Type', 'Authorization']` | 允许的请求头 |
| `credentials` | bool | `false` | 是否允许携带凭证 |

#### 重要说明

- **Long-Polling 传输**：当使用多进程（workerCount > 1）时，**不支持** HTTP Long-Polling 传输方式。
- **WebSocket 传输**：在多进程模式下，WebSocket 传输完全支持，**必须使用** WebSocket 连接。
- **客户端连接**：在多进程模式下，客户端需要显式使用 WebSocket 传输。

#### 多worker配置示例

```php
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\ClusterAdapter;

// 创建多worker服务器实例（4个worker）
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // 设置4个worker
]);

// 创建并设置集群适配器
$adapter = new ClusterAdapter([
    'channel_ip' => '127.0.0.1',
    'channel_port' => 2206,
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);
```

### 2. 事件处理

#### 连接事件

```php
// 使用 $io->of() 注册命名空间的连接事件处理器
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 连接成功时发送欢迎消息
    $socket->emit('welcome', 'Welcome to /chat namespace!');
    
    // 断开连接事件处理器
    $socket->on('disconnect', function () use ($socket) {
        // 可以在这里添加断开连接的清理逻辑
    });
});
```

#### 自定义事件

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 聊天消息事件处理器
    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });
    
    // 通用消息事件处理器
    $socket->on('message', function ($msg) use ($socket) {
        $socket->emit('message', $msg);
    });
    
    // 自定义事件处理器
    $socket->on('customEvent', function ($msg = null) use ($io) {
        // 向所有 /chat 命名空间的客户端群发消息
        $io->of('/chat')->emit('customEvent', $msg);
    });
});
```

#### ACK机制（双向确认）

**重要：ACK 回复必须使用 callback，不要使用 return！**

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // ACK消息事件处理器 - 服务器确认客户端消息
    $socket->on('ack', function ($msg, $callback = null) use ($socket) {
        if (is_callable($callback)) {
            // 通过 callback 发送 ACK 回复
            $callback(['status' => 'ok', 'data' => $msg]);
        }
    });
    
    // 服务器发送带 ACK 的消息给客户端，等待客户端确认
    $socket->on('reqAck', function ($msg, $callback = null) use ($socket) {
        // 发送带 ACK 的消息给客户端
        $socket->emitWithAck('ackResponse', 'Server ACK response', function($userdata) {
            // 处理客户端的 ACK 回调
        });
        
        // 如果客户端也发送了 ACK ID，回复客户端
        if (is_callable($callback)) {
            $callback(['status' => 'ok']);
        }
    });
    
    // 双向 ACK 示例
    $socket->on('twoWay', function ($data, $callback = null) use ($socket) {
        $socket->emit('twoWay', 'Server response', function($clientResponse) use ($socket, $callback) {
            // 收到客户端的回复后，再回复给原始客户端
            if (is_callable($callback)) {
                $callback(['status' => 'ok', 'twoWay' => 'success', 'client' => $clientResponse]);
            }
        });
    });
});
```

#### 二进制数据处理

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 二进制数据处理
    $socket->on('buffer', function ($msg) use ($socket) {
        $socket->emitBinary('binaryResponse', $msg, ['status' => 'ok']);
    });
    
    // 文件上传处理
    $socket->on('file', function ($data) use ($socket) {
        if (isset($data['name']) && isset($data['data'])) {
            $fileName = $data['name'];
            $fileData = $data['data'];
            $fileSize = strlen($fileData);
            // 这里可以添加文件存储逻辑
            $socket->emit('message', "File received: {$fileName} ({$fileSize} bytes)");
        }
    });
    
    // TypedArray 处理
    $socket->on('typedArray', function ($data) use ($socket) {
        $socket->emit('binaryResponse', ['status' => 'ok', 'data' => $data]);
    });
});
```

### 3. 命名空间

**使用 $io->of() 方法，不要使用 $io->on() 的第三个参数！**

```php
// 推荐方式：使用 $io->of()
$io->of('/test')->on('connection', function ($socket) use ($io) {
    // 连接成功时发送欢迎消息
    $socket->emit('welcome', 'Welcome to /test namespace!');
    
    // 测试消息事件
    $socket->on('message', function ($msg) use ($socket) {
        $socket->emit('message', 'Test namespace: ' . $msg);
    });
    
    // 断开连接事件
    $socket->on('disconnect', function () use ($socket) {
        // 清理逻辑
    });
});

// 根命名空间也使用 $io->of()
$io->of('/')->on('connection', function ($socket) {
    $socket->emit('welcome', 'Welcome to root namespace!');
});
```

## 高级功能

### 1. 三层中间件系统

#### 全局中间件

```php
// 全局中间件 - 作用于所有命名空间和事件
$io->use(function ($socket, $packet, $next) {
    $sid = $socket['id'] ?? 'unknown';
    $type = $packet['type'] ?? 'unknown';
    $namespace = $socket['namespace'] ?? '/';
    
    echo "[Global Middleware] Request: SID={$sid}, Type={$type}, Namespace={$namespace}\n";
    
    // 验证 auth（如果是连接请求）
    if ($packet['type'] === 'CONNECT') {
        $auth = $packet['auth'] ?? null;
        if ($auth) {
            echo "[Global Middleware] Auth: " . json_encode($auth) . "\n";
        }
    }
    
    // 继续处理
    $next();
});

// 第二个全局中间件
$io->use(function ($socket, $packet, $next) {
    // 消息过滤
    if (in_array($packet['type'], ['EVENT', 'BINARY_EVENT'])) {
        $eventName = $packet['event'] ?? '';
        echo "[Global Middleware] Event: {$eventName}\n";
    }
    
    $next();
});
```

#### 命名空间级中间件

```php
// 命名空间级中间件 - 只作用于 /chat 命名空间
$io->of('/chat')->use(function ($socket, $packet, $next) {
    echo "[Namespace Middleware] /chat namespace\n";
    $next();
});
```

#### Socket实例级中间件

```php
$io->of('/chat')->on('connection', function ($socket) {
    // Socket 实例级中间件 - 只作用于当前 socket 连接
    $socket->use(function ($packet, $next) use ($socket) {
        $eventName = $packet['event'] ?? 'unknown';
        echo "[Socket Middleware] Socket {$socket->id} received event: {$eventName}\n";
        
        // 验证事件名称，只允许特定事件
        $allowedEvents = ['chat message', 'message', 'ping', 'customEvent'];
        if (in_array($eventName, $allowedEvents)) {
            $next();
        } else {
            echo "[Socket Middleware] Blocked unauthorized event: {$eventName}\n";
            // 不调用 $next() 阻止事件继续处理
        }
    });
    
    // 第二个 Socket 实例中间件 - 数据验证
    $socket->use(function ($packet, $next) use ($socket) {
        $eventName = $packet['event'] ?? '';
        $data = $packet['data'] ?? [];
        
        // 对特定事件进行数据验证
        if ($eventName === 'chat message') {
            $message = $data[0] ?? '';
            if (is_string($message) && strlen($message) > 0 && strlen($message) <= 1000) {
                // 验证通过
                $next();
            } else {
                echo "[Socket Middleware] Message validation failed\n";
                $socket->emit('error', 'Message format invalid');
            }
        } else {
            // 其他事件直接通过
            $next();
        }
    });
});
```

### 2. 房间管理

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 加入房间
    $socket->join('room1');
    
    // 离开房间
    $socket->leave('room1');
    
    // 只向指定房间发送
    $io->of('/chat')->to('room1')->emit('some event', 'message');
    
    // 向多个房间发送
    $io->of('/chat')->to('room1')->to('room2')->emit('some event', 'message');
    
    // 广播（排除当前 socket）
    $socket->broadcast->emit('some event', 'message');
    
    // 在指定房间内广播
    $socket->to('room1')->emit('some event', 'message');
    
    // 排除特定房间
    $socket->except('room2')->emit('some event', 'message');
});
```

### 3. 广播

```php
// 向根命名空间的所有客户端广播消息
$io->emit('broadcast', 'Hello everyone!');

// 向指定命名空间的所有客户端广播消息
$io->of('/chat')->emit('broadcast', 'Hello chat room!');

// 向指定房间的所有客户端广播消息
$io->of('/chat')->to('room1')->emit('broadcast', 'Hello room1!');
```

### 4. 集群模式

```php
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\RedisAdapter;

// 创建多worker服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'workerCount' => 4,
]);

// 设置 Redis 适配器（跨进程通信）
$adapter = new RedisAdapter([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,
    'db' => 0,
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);
```

## 日志系统

### 内置日志器

```php
use Psr\Log\LogLevel;

$io = new SocketIOServer('0.0.0.0:8088', [
    'logLevel' => LogLevel::DEBUG
]);
```

### 自定义日志处理器

```php
$io->getLogger()->setHandler(function ($level, $message, $context) {
    // 写入文件、数据库、或者其他日志服务
    file_put_contents('/path/to/logs/socketio.log',
        "[{$level}] {$message}\n", FILE_APPEND
    );
});
```

### 使用第三方 PSR-3 日志库（如 Monolog）

```php
$logger = new \Monolog\Logger('socketio');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/path/to/logs/socketio.log'));
$io->setLogger($logger);
```

## API参考

### SocketIOServer 类

#### 构造函数

```php
public function __construct(string $listen, array $options = [])
```

#### 主要方法

| 方法 | 描述 |
| --- | --- |
| `on($event, callable $handler)` | 注册根命名空间的事件处理器 |
| `emit(string $event, mixed ...$args)` | 向所有根命名空间的客户端广播消息 |
| `to($room)` | 创建指向指定房间的广播器 |
| `of($namespace)` | 获取指定命名空间的 SocketNamespace 实例 |
| `use(callable $middleware)` | 注册全局中间件 |
| `setAdapter($adapter)` | 设置跨进程适配器 |
| `getLogger()` | 获取日志器实例 |
| `setLogger($logger)` | 设置 PSR-3 日志器 |
| `start()` | 启动服务器 |

### SocketNamespace 类

#### 主要方法

| 方法 | 描述 |
| --- | --- |
| `on($event, callable $handler)` | 注册命名空间事件处理器 |
| `emit(string $event, mixed ...$args)` | 向命名空间的所有客户端广播消息 |
| `to($room)` | 创建指向指定房间的广播器 |
| `use(callable $middleware)` | 注册命名空间级中间件 |

### Socket 类

#### 主要方法

| 方法 | 描述 |
| --- | --- |
| `emit(string $event, mixed ...$args)` | 发送事件到客户端 |
| `emitBinary(string $event, mixed ...$args)` | 发送二进制事件到客户端 |
| `emitWithAck($event, ...$args)` | 发送带 ACK 的事件，最后一个参数是回调函数 |
| `join(string $room)` | 加入房间 |
| `leave(string $room)` | 离开房间 |
| `to($room)` | 创建指向指定房间的广播器 |
| `except($rooms)` | 创建排除指定房间的广播器 |
| `broadcast()` | 广播事件到除了自己以外的其他连接 |
| `disconnect($close = false)` | 断开连接 |
| `use(callable $middleware)` | 注册 Socket 实例级中间件 |
| `off(string $event, ?callable $callback)` | 移除事件监听器 |
| `once(string $event, callable $callback)` | 注册一次性事件监听器 |
| `removeAllListeners(?string $event)` | 移除所有或指定事件监听器 |
| `listeners(string $event)` | 获取指定事件的监听器列表 |
| `hasListeners(string $event)` | 检查是否有指定事件的监听器 |

#### Socket 属性

| 属性 | 类型 | 描述 |
| --- | --- | --- |
| `id` | string | Socket ID (Session ID) |
| `namespace` | string | 当前命名空间 |
| `auth` | mixed | 认证信息 |
| `handshake` | array | 握手信息对象 |
| `data` | array | 自定义数据存储（跨 Socket 实例共享） |
| `conn` | SocketConn | 传输层连接信息 |
| `rooms` | Set | 当前 Socket 加入的所有房间 |

#### socket.conn 对象

```php
$io->on('connection', function ($socket) {
    // 传输层信息
    $conn = $socket->conn;
    
    echo "传输方式: " . $conn->transport;        // 'websocket' 或 'polling'
    echo "客户端地址: " . $conn->remoteAddress;   // 客户端 IP
    echo "是否安全连接: " . ($conn->secure ? 'yes' : 'no');
    echo "连接时间: " . $conn->createdAt;
    
    // 关闭底层连接
    $conn->close();
});
```

#### socket.rooms 属性

```php
$io->on('connection', function ($socket) {
    // 获取当前加入的所有房间
    $rooms = $socket->rooms;
    
    // 遍历房间
    foreach ($rooms as $room) {
        echo "房间: {$room}\n";
    }
    
    // 检查是否在某个房间
    if ($rooms->has('chat')) {
        echo "在 chat 房间中\n";
    }
    
    // 房间数量
    echo "房间数: " . count($rooms) . "\n";
});
```

#### socket.broadcast 属性

```php
$io->on('connection', function ($socket) {
    // 向除自己以外的所有 Socket 广播
    $socket->broadcast->emit('message', '有人加入了');
    
    // 向除自己以外的指定房间广播
    $socket->broadcast->to('room1')->emit('message', '有人加入了 room1');
});
```

#### handshake 对象结构

`socket.handshake` 对象包含完整的握手信息：

```php
$io->of('/chat')->on('connection', function ($socket) {
    // 获取握手信息
    $handshake = $socket->handshake;
    
    // 客户端 IP 地址
    $clientIp = $handshake['address'];
    
    // 请求头
    $headers = $handshake['headers'];
    $userAgent = $headers['user-agent'] ?? 'Unknown';
    $origin = $headers['origin'] ?? null;
    
    // 是否跨域
    $isCrossDomain = $handshake['xdomain'];
    
    // 是否安全连接 (HTTPS/WSS)
    $isSecure = $handshake['secure'];
    
    // 连接时间
    $connectTime = $handshake['time'];
    $issued = $handshake['issued']; // 时间戳（毫秒）
    
    // 请求 URL
    $url = $handshake['url'];
    
    // 查询参数
    $query = $handshake['query'];
    
    // 认证信息（如果客户端提供了）
    $auth = $handshake['auth'];
});
```

| 字段 | 类型 | 描述 |
| --- | --- | --- |
| `headers` | array | HTTP 请求头 |
| `time` | string | 连接时间 (ISO 8601 格式) |
| `issued` | int | 连接时间戳（毫秒） |
| `address` | string | 客户端 IP 地址 |
| `xdomain` | bool | 是否跨域请求 |
| `secure` | bool | 是否安全连接 (HTTPS/WSS) |
| `url` | string | 请求 URL |
| `query` | array | 查询参数 |
| `auth` | mixed | 认证信息 |

## 服务器级别 API

### socketsJoin / socketsLeave

让所有已连接的 Socket 加入或离开指定房间：

```php
// 让所有 Socket 加入 "announcement" 房间
$io->socketsJoin('announcement');

// 让所有 Socket 离开 "announcement" 房间
$io->socketsLeave('announcement');

// 指定命名空间
$io->socketsJoin('announcement', '/chat');

// 多个房间
$io->socketsJoin(['room1', 'room2']);

// 通过命名空间对象
$io->of('/chat')->socketsJoin('announcement');
$io->of('/chat')->socketsLeave('announcement');
```

### fetchSockets

获取命名空间内所有 Socket 实例：

```php
// 获取所有 Socket
$sockets = $io->fetchSockets();

// 指定命名空间
$sockets = $io->fetchSockets('/chat');

// 通过命名空间对象
$sockets = $io->of('/chat')->fetchSockets();

foreach ($sockets as $socket) {
    echo "Socket ID: {$socket->id}\n";
    echo "房间: " . implode(', ', $socket->rooms->values()) . "\n";
    echo "自定义数据: " . json_encode($socket->data) . "\n";
}
```

### allSockets

获取命名空间内所有 Socket ID 的 Set 集合：

```php
// 获取所有 Socket ID
$socketIds = $io->allSockets();

// 指定命名空间
$socketIds = $io->of('/chat')->allSockets();

echo "在线数量: " . count($socketIds) . "\n";

foreach ($socketIds as $sid) {
    echo "在线: {$sid}\n";
}
```

### except

排除指定房间进行广播：

```php
// 排除 "room1" 房间的所有成员
$io->except('room1')->emit('message', 'Hello');

// 排除多个房间
$io->except(['room1', 'room2'])->emit('message', 'Hello');

// 通过命名空间对象
$io->of('/chat')->except('room1')->emit('message', 'Hello');
```

### serverSideEmit

在多 worker 集群环境中向其他 worker 发送事件：

```php
// 向其他 worker 发送事件
$io->serverSideEmit('custom-event', ['data' => 'value']);

// 接收其他 worker 发送的事件
$io->on('custom-event', function ($data) {
    echo "收到其他 worker 的事件: " . json_encode($data) . "\n";
});
```

### disconnecting 事件

Socket 断开连接前触发，可以获取断开原因：

```php
$io->on('connection', function ($socket) {
    $socket->on('disconnecting', function ($reason) {
        echo "Socket 即将断开，原因: {$reason}\n";
        // 此时 Socket 仍在房间中，可以执行清理操作
    });
    
    $socket->on('disconnect', function ($reason) {
        echo "Socket 已断开，原因: {$reason}\n";
        // 此时 Socket 已离开所有房间
    });
});
```

### 事件监听器管理

```php
$io->on('connection', function ($socket) {
    // 注册一次性监听器
    $socket->once('init', function ($data) use ($socket) {
        echo "只触发一次: {$data}\n";
    });
    
    // 移除指定事件监听器
    $handler = function ($data) { echo $data; };
    $socket->on('message', $handler);
    $socket->off('message', $handler);
    
    // 移除指定事件的所有监听器
    $socket->off('message');
    
    // 移除所有事件的所有监听器
    $socket->removeAllListeners();
    
    // 检查是否有监听器
    if ($socket->hasListeners('message')) {
        echo "有 message 监听器\n";
    }
    
    // 获取监听器列表
    $listeners = $socket->listeners('message');
});
```

## 客户端示例

### 浏览器客户端

```html
<script src="https://cdn.socket.io/4.5.0/socket.io.min.js"></script>
<script>
    // 连接到 Socket.IO 服务器（指定命名空间）
    const socket = io('http://localhost:8088/chat');
    
    // 连接事件
    socket.on('connect', () => {
        console.log('Connected to server');
    });
    
    // 欢迎消息事件
    socket.on('welcome', (message) => {
        console.log('Welcome message:', message);
    });
    
    // 聊天消息事件
    socket.on('chat message', (message) => {
        console.log('Chat message:', message);
    });
    
    // 发送聊天消息
    function sendMessage() {
        const message = 'Hello from client';
        socket.emit('chat message', message);
    }
    
    // 发送带 ACK 的消息
    function sendWithAck() {
        socket.emit('ack', 'Hello with ACK', (response) => {
            console.log('Server ACK response:', response);
        });
    }
    
    // 断开连接事件
    socket.on('disconnect', () => {
        console.log('Disconnected from server');
    });
</script>
```

### Node.js客户端

```javascript
const io = require('socket.io-client');

// 连接到 Socket.IO 服务器
const socket = io('http://localhost:8088/chat');

// 连接事件
socket.on('connect', () => {
    console.log('Connected to server');
});

// 欢迎消息事件
socket.on('welcome', (message) => {
    console.log('Welcome message:', message);
});

// 发送带 ACK 的消息
socket.emit('ack', 'Hello from Node.js', (response) => {
    console.log('Server ACK response:', response);
});
```

## 故障排除

### 常见问题

1. **服务器无法启动**
   - 检查端口是否被占用
   - 检查依赖是否安装正确
   - 检查PHP版本是否符合要求（PHP 8.1+）
   
2. **客户端无法连接**
   - 检查服务器是否正在运行
   - 检查网络连接是否正常
   - 检查客户端连接地址是否正确
   - 确认命名空间路径是否正确（注意前导斜杠）

3. **服务器不回复 ACK**
   - 确认事件处理器中正确调用了 callback 参数
   - 确认没有使用 return 回复，只使用 callback
   - 检查日志中是否有错误信息

4. **消息发送失败**
   - 检查事件名称是否正确
   - 检查消息格式是否符合要求
   - 检查服务器日志是否有错误信息

5. **中间件未执行**
   - 确认中间件正确使用 $next() 继续处理
   - 确认中间件注册在正确的层级（全局/命名空间/Socket）
   - 检查 Socket 实例中间件是否在 connection 事件内注册

6. **Logger 类型错误**
   - 确保使用最新的 Logger 类，修复了布尔值处理问题
   - 不要直接传递布尔值给日志，先转换为字符串

### 日志管理

服务器日志默认输出到控制台，可以通过 setHandler 方法设置自定义日志处理器。日志级别包括：

- `LogLevel::DEBUG`
- `LogLevel::INFO`
- `LogLevel::NOTICE`
- `LogLevel::WARNING`
- `LogLevel::ERROR`
- `LogLevel::CRITICAL`
- `LogLevel::ALERT`
- `LogLevel::EMERGENCY`

## 性能优化

1. **使用WebSocket传输**：WebSocket比HTTP轮询更高效，减少了网络开销
2. **合理设置心跳间隔**：根据实际需求调整心跳间隔，避免过于频繁的心跳
3. **使用房间和命名空间**：合理组织客户端，减少不必要的消息广播
4. **优化事件处理逻辑**：避免在事件处理器中执行耗时操作
5. **使用集群模式**：在高并发场景下，使用集群模式提高性能
6. **合理设置日志级别**：生产环境使用 INFO 或 WARNING 级别，减少日志开销

## 安全注意事项

1. **验证客户端身份**：使用中间件验证客户端身份，防止未授权访问
2. **限制消息大小**：设置合理的最大负载限制，防止恶意客户端发送过大的消息
3. **过滤事件名称**：验证事件名称格式，防止恶意输入
4. **使用HTTPS**：在生产环境中使用HTTPS，加密传输数据
5. **定期清理会话**：避免会话泄漏，定期清理过期会话
6. **验证数据**：在事件处理器中验证和过滤所有输入数据

## 版本历史

- **v1.4.0**：PHP 8.1+优化，完整的三层中间件系统，修复ACK机制，统一$io->of()用法
- **v1.3.0**：优化性能和稳定性，添加PSR-3日志
- **v1.2.0**：添加集群模式支持
- **v1.1.0**：添加二进制数据传输支持
- **v1.0.0**：初始版本，支持Socket.IO v4协议

## 贡献指南

欢迎提交Issue和Pull Request来改进这个项目。在提交代码前，请确保：

1. 代码符合项目的代码风格（遵循 PSR 规范）
2. 添加了适当的测试
3. 文档已经更新
4. 所有 PHP 语法检查通过

## 许可证

本项目使用MulanPSL-2.0许可证，详情请查看LICENSE文件。
