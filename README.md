# PHPSocket.IO

[Socket.IO](https://socket.io) 的 PHP 服务器实现，支持 WebSocket 和 Long-Polling 传输，兼容 Socket.IO 协议 v4。

## 特性

- **多传输协议支持**：支持 WebSocket 和 HTTP Long-Polling（多进程模式下仅支持 WebSocket，且需要显式使用）
- **二进制数据支持**：支持二进制事件传输
- **集群支持**：通过 Channel 或 Redis 实现多进程集群通信
- **房间管理**：支持房间（Room）管理功能
- **命名空间**：支持多个命名空间，推荐使用 $io->of() 方法
- **事件确认（ACK）**：支持双向 ACK 确认机制，强制使用 callback 回复
- **三层中间件系统**：支持全局、命名空间级别和 Socket 实例级别的中间件
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
│   ├── Broadcaster.php           # 统一广播器
│   ├── EngineIOHandler.php       # Engine.IO协议处理器
│   ├── EventHandler.php          # 事件处理器
│   ├── HttpRequestHandler.php    # HTTP请求处理器
│   ├── Logger.php                # PSR-3 兼容日志器
│   ├── MiddlewareHandler.php     # 中间件处理器
│   ├── PacketParser.php          # 数据包解析器
│   ├── PollingHandler.php        # 轮询处理器
│   ├── RoomManager.php           # 房间管理器
│   ├── ServerManager.php         # 服务器管理器
│   ├── Session.php               # 会话管理
│   ├── Socket.php                # Socket类
│   ├── SocketNamespace.php       # 命名空间处理类
│   └── SocketIOServer.php        # Socket.IO服务器主类
├── examples/                     # 示例目录
│   └── simple-chat/              # 简单聊天示例
├── tests/                        # 测试目录
├── server.php                    # 服务器启动脚本
├── index.html                    # 客户端示例
├── README.md                     # 项目说明文档（中文）
├── README.en.md                  # 项目说明文档（英文）
├── USAGE.md                      # 详细使用文档（中文）
└── LICENSE                       # 许可证文件
```

## 核心文件说明

- **src/SocketIOServer.php**：Socket.IO 服务器主类，负责处理连接和事件分发
- **src/SocketNamespace.php**：命名空间处理类，通过 $io->of() 访问
- **src/EventHandler.php**：事件处理器，负责处理各种 Socket.IO 事件
- **src/HttpRequestHandler.php**：HTTP 请求处理器，处理 HTTP 轮询和 WebSocket 握手
- **src/EngineIOHandler.php**：Engine.IO 协议处理器，处理底层传输协议
- **src/Session.php**：会话管理，管理客户端会话状态
- **src/Socket.php**：Socket 类，封装客户端连接接口
- **src/RoomManager.php**：房间管理器，处理房间相关操作
- **src/PacketParser.php**：数据包解析器，解析 Socket.IO 数据包
- **src/Broadcaster.php**：统一广播器，负责消息广播
- **src/Logger.php**：PSR-3 兼容日志器

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
    // 发送欢迎消息
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
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
        // 清理逻辑
    });
});

// 启动服务器
$io->start();
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

## 配置选项

| 选项 | 类型 | 默认值 | 描述 |
| --- | --- | --- | --- |
| `pingInterval` | int | 25000 | 心跳间隔（毫秒） |
| `pingTimeout` | int | 20000 | 心跳超时（毫秒） |
| `maxPayload` | int | 10485760 | 最大负载大小（字节） |
| `workerCount` | int | 1 | Worker 进程数量 |
| `logLevel` | string | `LogLevel::INFO` | 日志级别（PSR-3） |
| `ssl` | array | [] | SSL 配置（用于 HTTPS/WSS） |

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

详细使用说明请参考 [USAGE.md](USAGE.md) 文件，包括：
- 完整的三层中间件系统文档
- 命名空间正确用法
- ACK 机制详细说明
- API 参考文档
- 故障排除指南
- 性能优化建议
- 安全注意事项

## 贡献指南

欢迎提交 Issue 和 Pull Request 来改进这个项目。在提交代码前，请确保：

1. 代码符合项目的代码风格（遵循 PSR 规范）
2. 添加了适当的测试
3. 文档已经更新（README.md, USAGE.md 等）
4. 所有 PHP 语法检查通过

## 许可证

本项目采用 MulanPSL-2.0 许可证，详见 [LICENSE](LICENSE) 文件。
