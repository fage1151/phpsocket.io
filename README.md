# PHPSocket.IO

[Socket.IO](https://socket.io) 的 PHP 服务器实现，支持 WebSocket 和 Long-Polling 传输，完整兼容 Socket.IO 协议 v4。

基于 Workerman 实现的高性能、生产级别的 Socket.IO 服务器，专为 PHP 环境优化。

## 特性

- **多传输协议支持**：支持 WebSocket 和 HTTP Long-Polling（多进程模式下仅支持 WebSocket，且需要显式使用）
- **二进制数据支持**：支持二进制事件传输
- **集群支持**：通过 Channel 或 Redis 实现多进程集群通信
- **房间管理**：支持房间（Room）管理功能
- **命名空间**：支持多个命名空间，推荐使用 $io->of() 方法
- **事件确认（ACK）**：支持双向 ACK 确认机制，强制使用 callback 回复
- **三层中间件系统**：支持全局、命名空间级别和 Socket 实例级别的中间件
- **连接恢复机制**：完整支持 Socket.IO v4 连接状态恢复（private id, offset tracking, state recovery）
- **心跳检测**：自动心跳保活机制
- **PSR-3 日志系统**：支持 PSR-3 标准日志接口
- **PHP 8.1+ 优化实现**：使用 PHP 8.1 最新特性，性能优化

## 系统要求

- PHP >= 8.1
- Workerman >= 4.0
- psr/log >= 3.0
- 可选依赖：
  - workerman/channel（使用 ClusterAdapter 时需要）
  - workerman/redis（使用 RedisAdapter 时需要）

## 安装

### 从 GitHub 克隆

```bash
# 克隆项目
git clone https://github.com/phpsocketio/socket.io.git

# 进入项目目录
cd socket.io

# 安装依赖
composer install
```

### 安装可选依赖

#### 使用 ClusterAdapter 时：

ClusterAdapter 基于 Workerman Channel，默认不包含，需要安装：

```bash
composer require workerman/channel
```

#### 使用 RedisAdapter 时：

```bash
composer require workerman/redis
```

## 项目结构

```
├── src/                          # 核心源码目录
│   ├── Adapter/                  # 适配器目录
│   │   ├── AdapterInterface.php  # 适配器接口
│   │   ├── ClusterAdapter.php    # 基于Channel的集群适配器
│   │   └── RedisAdapter.php      # 基于Redis的集群适配器
│   ├── Protocol/                 # 协议相关
│   │   ├── PacketParser.php      # 数据包解析器
│   │   └── EngineIOHandler.php   # Engine.IO协议处理器
│   ├── Transport/                # 传输层
│   │   ├── HttpRequestHandler.php # HTTP请求处理器
│   │   ├── PollingHandler.php    # 轮询处理器
│   │   └── ConnectionManager.php # 连接管理器
│   ├── Room/                     # 房间管理
│   │   └── RoomManager.php       # 房间管理器
│   ├── Event/                    # 事件系统
│   │   ├── EventHandler.php      # 事件处理器
│   │   └── MiddlewarePipeline.php # 中间件执行管道
│   ├── Support/                  # 支持类
│   │   ├── Logger.php            # PSR-3 兼容日志器
│   │   ├── ErrorHandler.php      # 错误处理器
│   │   ├── SocketConn.php        # Socket连接信息类
│   │   ├── Set.php               # 集合数据结构
│   │   └── ServerManager.php     # 服务器管理器
│   ├── SocketIOServer.php        # Socket.IO服务器主类
│   ├── Socket.php                # Socket类
│   ├── SocketNamespace.php       # 命名空间处理类
│   ├── Session.php               # 会话管理
│   └── Broadcaster.php           # 统一广播器
├── examples/                     # 示例目录
│   ├── server.php                # 完整服务器示例
│   ├── test_ack_fix.php          # ACK测试
│   └── test_logger_fix.php       # 日志测试
├── docs/                         # 文档目录
│   └── API.md                    # API参考文档
├── tests/                        # 测试目录
├── README.md                     # 项目说明文档（中文）
├── README.en.md                  # 项目说明文档（英文）
├── USAGE.md                      # 详细使用文档（中文）
├── composer.json                 # Composer配置
├── phpstan.neon                  # PHPStan配置
├── phpcs.xml                     # PHPCS配置
└── LICENSE                       # 许可证文件
```

## 核心文件说明

### 核心类（根目录）
- **src/SocketIOServer.php**：Socket.IO 服务器主类，负责处理连接和事件分发
- **src/SocketNamespace.php**：命名空间处理类，通过 $io->of() 访问
- **src/Session.php**：会话管理，管理客户端会话状态和连接恢复
- **src/Socket.php**：Socket 类，封装客户端连接接口
- **src/Broadcaster.php**：统一广播器，负责消息广播

### 协议相关（Protocol/）
- **src/Protocol/PacketParser.php**：数据包解析器，解析和构建 Socket.IO 数据包
- **src/Protocol/EngineIOHandler.php**：Engine.IO 协议处理器，处理底层传输协议

### 传输层（Transport/）
- **src/Transport/HttpRequestHandler.php**：HTTP 请求处理器，处理 HTTP 轮询和 WebSocket 握手
- **src/Transport/PollingHandler.php**：轮询处理器
- **src/Transport/ConnectionManager.php**：连接管理器

### 房间管理（Room/）
- **src/Room/RoomManager.php**：房间管理器，处理房间相关操作

### 事件系统（Event/）
- **src/Event/EventHandler.php**：事件处理器，负责处理各种 Socket.IO 事件
- **src/Event/MiddlewarePipeline.php**：中间件执行管道

### 支持类（Support/）
- **src/Support/Logger.php**：PSR-3 兼容日志器
- **src/Support/ErrorHandler.php**：错误处理器
- **src/Support/Set.php**：集合数据结构，类似 JavaScript 的 Set
- **src/Support/SocketConn.php**：Socket 连接信息类
- **src/Support/ServerManager.php**：服务器管理器

## 快速开始

### 基本用法

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Psr\Log\LogLevel;

// 创建 Socket.IO 服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // 心跳间隔（毫秒）
    'pingTimeout'  => 20000,  // 心跳超时（毫秒）
    'maxPayload'   => 10485760, // 最大负载（字节）
    'workerCount'  => 1,       // worker 数量，默认为 1
    'logLevel'     => LogLevel::INFO, // 日志级别
]);

// 使用 $io->of() 注册命名空间的连接事件处理
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 检查是否是重连恢复的连接
    if ($socket->recovered) {
        echo "连接已恢复，继续使用之前的数据！\n";
        // $socket->data 和房间信息已自动恢复
        // 可以重发可能丢失的消息
    }
    
    // 发送欢迎消息
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
    // 设置 socket 的自定义数据（断连时会保存，重连时会恢复）
    $socket->data['userId'] = 'user_' . time();
    $socket->data['name'] = 'Guest';
    
    // 加入房间（断连时会保存，重连时会自动恢复）
    $socket->join('welcome');
    
    // 聊天消息处理
    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });
    
    // ACK 消息处理 - 强制使用 callback，不要使用 return
    $socket->on('ack', function ($msg, $callback = null) use ($socket) {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
    });
    
    // 断开连接处理
    $socket->on('disconnect', function () use ($socket) {
        // 清理逻辑（注意：断开时会自动保存状态用于恢复）
    });
});

// 启动 Workerman
Worker::runAll();
```

### 使用日志

```php
use Psr\Log\LogLevel;

// 1. 使用内置日志器（默认）
$io = new SocketIOServer('0.0.0.0:8088', [
    'logLevel' => LogLevel::DEBUG
]);

// 2. 设置自定义日志处理器
$io->getLogger()->setHandler(function ($level, $message, $context) {
    // 这里可以写入文件、数据库、或者其他日志服务
    file_put_contents('/path/to/logs/socketio.log',
        "[{$level}] {$message}\n", FILE_APPEND
    );
});

// 3. 使用第三方 PSR-3 日志库，如 Monolog
$logger = new \Monolog\Logger('socketio');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/path/to/logs/socketio.log'));
$io->setLogger($logger);
```

### 使用中间件

```php
// 1. 全局中间件 - 作用于所有命名空间和事件
$io->use(function ($socket, $packet, $next) {
    $sid = $socket['id'] ?? 'unknown';
    echo "[Global Middleware] SID: {$sid}\n";
    $next();
});

// 2. 命名空间级中间件 - 只作用于 /chat 命名空间
$io->of('/chat')->use(function ($socket, $packet, $next) {
    echo "[Namespace Middleware] /chat\n";
    $next();
});

// 3. Socket 实例级中间件 - 只作用于当前连接
$io->of('/chat')->on('connection', function ($socket) {
    $socket->use(function ($packet, $next) use ($socket) {
        echo "[Socket Middleware] Socket {$socket->id}\n";
        $next();
    });
});
```

### 多 worker 配置示例

当需要使用多个 worker 进程时，必须通过 setAdapter 方法设置 adapter：

#### 使用 ClusterAdapter（基于 Workerman Channel）

> 注意：使用 ClusterAdapter 需要先安装 workerman/channel：
> ```bash
> composer require workerman/channel
> ```

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\ClusterAdapter;

// 创建多 worker 服务器实例（4个 worker）
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // 设置 4 个 worker
]);

// 创建并设置集群适配器
$adapter = new ClusterAdapter([
    'channel_ip' => '127.0.0.1',
    'channel_port' => 2206,
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);

// 事件处理代码...
$io->of('/chat')->on('connection', function ($socket) {
    // 处理连接...
});

Worker::runAll();
```

#### 使用 RedisAdapter（基于 Redis）

> 注意：使用 RedisAdapter 需要先安装 workerman/redis：
> ```bash
> composer require workerman/redis
> ```

```php
use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\RedisAdapter;

// 创建多 worker 服务器实例（4个 worker）
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // 设置 4 个 worker
]);

// 创建并设置 Redis 适配器
$adapter = new RedisAdapter([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,  // Redis 认证密码，无密码时为 null
    'db' => 0,       // Redis 数据库编号
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);

// 事件处理代码...
$io->of('/chat')->on('connection', function ($socket) {
    // 处理连接...
});

Worker::runAll();
```

## 房间操作

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

## 事件确认（ACK）

**重要：ACK 回复必须使用 callback，不要使用 return！**

```php
$io->of('/chat')->on('connection', function ($socket) {
    $socket->on('reqAck', function ($data, $callback = null) {
        // 处理数据
        $result = ['status' => 'ok', 'data' => $data];
        
        // 调用 callback 发送确认
        if (is_callable($callback)) {
            $callback($result);
        }
    });
    
    // 服务器发送带 ACK 的消息给客户端
    $socket->on('ping', function () use ($socket) {
        $socket->emitWithAck('ackResponse', 'Hello', function ($clientData) {
            // 处理客户端回复
        });
    });
});
```

## 连接恢复机制

本项目完整实现了 Socket.IO v4 连接状态恢复机制，当客户端网络不稳定导致断连后，可以恢复之前的会话状态。

### 特性

- **自动保存状态**：断开连接时自动保存房间信息、自定义数据和已发送消息
- **状态恢复**：重连时自动恢复房间和自定义数据
- **丢失消息重发**：通过 offset 跟踪，自动重发可能丢失的消息
- **简便使用**：通过 `$socket->recovered` 属性检查连接是否成功恢复

### 使用示例

```php
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    // 检查连接是否已恢复
    if ($socket->recovered) {
        echo "用户已恢复连接！\n";
        // $socket->data 和房间信息已自动恢复
        // 可以检查 $socket->data 中是否有需要续传的数据
    } else {
        echo "新用户连接！\n";
        // 新连接，初始化数据
        $socket->data['username'] = 'Guest_' . mt_rand();
    }
    
    // 设置自定义数据（断连后会保存，重连后会恢复）
    $socket->data['lastActive'] = time();
    
    // 加入房间（断连后会保存，重连后会自动恢复）
    $socket->join('chat_room');
    
    // 发送消息（offset 会自动跟踪）
    $socket->emit('system_message', '欢迎来到聊天室！');
});
```

### 客户端配置

客户端需要启用重连机制（Socket.IO 客户端默认已启用）：

```javascript
const io = require('socket.io-client');

// 连接服务器（默认重连已启用）
const socket = io('http://localhost:8088/chat', {
    // 可选：自定义重连参数
    reconnection: true,
    reconnectionDelay: 100,
    reconnectionDelayMax: 500,
    reconnectionAttempts: 10,
});
```

### 工作原理

1. **连接建立**：服务器为每个连接生成唯一的 `pid`（private id）
2. **消息跟踪**：每个发送的事件都带有 `offset` 标记
3. **断连保存**：连接断开时，服务器保存：
   - 当前连接的所有房间
   - `$socket->data` 中的自定义数据
   - 最近发送的事件列表（带 offset）
4. **重连恢复**：
   - 客户端重连时发送之前的 `pid` 和最新的 `offset`
   - 服务器验证 pid 是否有效且未超时
   - 自动恢复房间信息和自定义数据
   - 重发 offset 之后可能丢失的消息
   - 设置 `$socket->recovered = true`
5. **过期清理**：保存的状态 120 秒后自动过期

### 配置选项

连接恢复机制内置在服务器中，无需额外配置。以下是默认参数：

- **恢复超时**：120 秒（超过此时间的状态无法恢复）
- **最大保存消息数**：1000 条（最多保存的事件数量）

## 配置选项

| 选项 | 类型 | 默认值 | 描述 |
| --- | --- | --- | --- |
| `pingInterval` | int | 25000 | 心跳间隔（毫秒） |
| `pingTimeout` | int | 20000 | 心跳超时（毫秒） |
| `maxPayload` | int | 10485760 | 最大负载大小（字节） |
| `workerCount` | int | 1 | Worker 进程数量 |
| `logLevel` | string | `LogLevel::INFO` | 日志级别（PSR-3） |
| `ssl` | array | [] | SSL 配置（用于 HTTPS/WSS） |
| `cors` | array/string | null | CORS 跨域配置 |

### CORS 配置

支持以下几种配置方式：

```php
// 方式1：简单配置，仅指定允许的源
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => 'https://example.com'
]);

// 方式2：完整配置
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => [
        'origin' => 'https://example.com',
        'methods' => ['GET', 'POST', 'OPTIONS'],
        'allowedHeaders' => ['my-custom-header', 'Content-Type'],
        'credentials' => true
    ]
]);

// 方式3：允许多个源（需要动态处理）
$io = new SocketIOServer('0.0.0.0:8088', [
    'cors' => [
        'origin' => ['https://example.com', 'https://app.example.com'],
        'methods' => ['GET', 'POST', 'OPTIONS'],
        'allowedHeaders' => ['Content-Type', 'Authorization'],
        'credentials' => true
    ]
]);
```

#### CORS 配置选项说明

| 选项 | 类型 | 默认值 | 描述 |
| --- | --- | --- | --- |
| `origin` | string/array | `*` | 允许的源，可以是字符串或数组 |
| `methods` | array | `['GET', 'POST', 'OPTIONS']` | 允许的 HTTP 方法 |
| `allowedHeaders` | array | `['Content-Type', 'Authorization']` | 允许的请求头 |
| `credentials` | bool | `false` | 是否允许携带凭证（Cookies） |

## 启动服务器

使用提供的 server.php 启动服务器：

```bash
# 启动服务器（前台运行）
php server.php

# 以守护进程方式启动（后台运行）
php server.php -d

# 查看服务器状态
php server.php status

# 停止服务器
php server.php stop
```

## 更多信息

详细使用说明请参考 [docs/USAGE.md](docs/USAGE.md) 文件，包括：
- 完整的三层中间件系统文档
- 命名空间正确用法
- ACK 机制详细说明
- API 参考文档
- 故障排除指南
- 性能优化建议
- 安全注意事项

## 版本历史

- **v1.5.0**：完整实现 Socket.IO v4 连接恢复机制，添加 `$socket->recovered` 属性，支持状态恢复、房间恢复和丢失消息重发
- **v1.4.0**：PHP 8.1+优化，完整的三层中间件系统，修复ACK机制，统一$io->of()用法
- **v1.3.0**：优化性能和稳定性，添加PSR-3日志
- **v1.2.0**：添加集群模式支持
- **v1.1.0**：添加二进制数据传输支持
- **v1.0.0**：初始版本，支持Socket.IO v4协议

## 贡献指南

欢迎提交 Issue 和 Pull Request 来改进这个项目。在提交代码前，请确保：

1. 代码符合项目的代码风格（遵循 PSR 规范）
2. 添加了适当的测试
3. 文档已经更新（README.md, USAGE.md 等）
4. 所有 PHP 语法检查通过

## 许可证

本项目采用 MulanPSL-2.0 许可证，详见 [LICENSE](LICENSE) 文件。
