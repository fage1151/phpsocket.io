# PHPSocket.IO

[Socket.IO](https://socket.io) 的 PHP 服务器实现，支持 WebSocket 和 Long-Polling 传输，兼容 Socket.IO 协议 v4。

## 特性

- **多传输协议支持**: 支持 WebSocket 和 HTTP Long-Polling
- **二进制数据支持**: 支持二进制事件传输
- **集群支持**: 通过 Redis 实现多进程集群通信
- **房间管理**: 支持房间（Room）管理功能
- **命名空间**: 支持多个命名空间
- **事件确认**: 支持 ACK 确认机制
- **中间件**: 支持连接和事件中间件
- **心跳检测**: 自动心跳保活机制

## 系统要求

- PHP > 8.0
- workerman/workerman >= 4.0
- 可选依赖：
  - workerman/channel（使用ClusterAdapter时需要）
  - workerman/redis（使用RedisAdapter时需要）

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

#### 使用ClusterAdapter时：

ClusterAdapter 基于 Workerman Channel，默认不包含，需要安装：

```bash
composer require workerman/channel
```

#### 使用RedisAdapter时：

```bash
composer require workerman/redis
```

## 项目结构

```
├── src/                # 核心源码目录
│   ├── Adapter/                # 适配器目录
│   │   ├── AdapterInterface.php    # 适配器接口
│   │   └── ClusterAdapter.php      # 集群适配器
│   ├── ClientSocket.php        # 客户端Socket类
│   ├── EngineIOHandler.php     # Engine.IO协议处理器
│   ├── EventHandler.php        # 事件处理器
│   ├── HttpRequestHandler.php  # HTTP请求处理器
│   ├── MiddlewareHandler.php   # 中间件处理器
│   ├── PacketParser.php        # 数据包解析器
│   ├── PollingHandler.php      # 轮询处理器
│   ├── RoomManager.php         # 房间管理器
│   ├── ServerManager.php       # 服务器管理器
│   ├── Session.php             # 会话管理
│   ├── Socket.php              # Socket类
│   ├── SocketIOServer.php      # Socket.IO服务器主类
│   └── SocketIOV4Parser.php    # Socket.IO v4协议解析器
├── server.php          # 服务器启动脚本
├── index.html          # 客户端示例
├── composer.json       # Composer配置文件
├── composer.lock       # Composer依赖锁定文件
├── README.md           # 项目说明文档
├── README.en.md        # 英文说明文档
├── USAGE.md            # 使用文档
└── LICENSE             # 许可证文件
```

## 核心文件说明

- **src/SocketIOServer.php**: Socket.IO服务器主类，负责处理连接和事件分发
- **src/EventHandler.php**: 事件处理器，负责处理各种Socket.IO事件
- **src/HttpRequestHandler.php**: HTTP请求处理器，处理HTTP轮询和WebSocket握手
- **src/EngineIOHandler.php**: Engine.IO协议处理器，处理底层传输协议
- **src/Session.php**: 会话管理，管理客户端会话状态
- **src/Socket.php**: Socket类，封装客户端连接接口
- **src/RoomManager.php**: 房间管理器，处理房间相关操作
- **src/PacketParser.php**: 数据包解析器，解析Socket.IO数据包

## 快速开始

### 基本用法

```php
use PhpSocketIO\SocketIOServer;

// 创建Socket.IO服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // 心跳间隔（毫秒）
    'pingTimeout'  => 20000,  // 心跳超时（毫秒）
    'maxPayload'   => 10485760, // 最大负载（字节）
    'workerCount'  => 1,       // worker数量，默认为1
]);

// 连接事件处理
$io->on('connection', function ($socket) use ($io) {
    // 发送欢迎消息
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
    // 聊天消息处理
    $socket->on('chat message', function ($msg) use ($socket) {
        $socket->broadcast->emit('chat message', $msg);
    });
    
    // 断开连接处理
    $socket->on('disconnect', function () use ($socket) {
        // 清理逻辑
    });
});

// 启动服务器
Worker::runAll();
```

### 多worker配置示例

当需要使用多个worker进程时，必须通过setAdapter方法设置adapter：

##### 使用ClusterAdapter（基于Workerman Channel）

> 注意：使用ClusterAdapter需要先安装workerman/channel：
> ```bash
> composer require workerman/channel
> ```

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

// 事件处理代码...

Worker::runAll();
```

##### 使用RedisAdapter（基于Redis）

> 注意：使用RedisAdapter需要先安装workerman/redis：
> ```bash
> composer require workerman/redis
> ```

```php
use PhpSocketIO\SocketIOServer;
use PhpSocketIO\Adapter\RedisAdapter;

// 创建多worker服务器实例（4个worker）
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
    'workerCount'  => 4,  // 设置4个worker
]);

// 创建并设置Redis适配器
$adapter = new RedisAdapter([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => null,  // Redis认证密码，无密码时为null
    'db' => 0,       // Redis数据库编号
    'prefix' => 'socketio_',
    'heartbeat' => 25
]);
$io->setAdapter($adapter);

// 事件处理代码...

Worker::runAll();
```

## 更多信息

详细使用说明请参考 [USAGE.md](USAGE.md) 文件。
