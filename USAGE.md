# Socket.IO PHP服务器使用文档

## 项目概述

Socket.IO PHP服务器是一个基于Workerman的Socket.IO v4兼容服务器，提供实时双向通信功能，支持WebSocket和HTTP轮询两种传输方式。

- **支持Socket.IO v4协议**
- **实时双向通信**
- **支持WebSocket和HTTP轮询**
- **支持房间和命名空间**
- **支持二进制数据传输**
- **支持ACK机制**
- **支持集群模式**

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
composer start

# 启动服务器（后台运行）
composer start-daemon

# 停止服务器
composer stop

# 重启服务器
composer restart

# 查看服务器状态
composer status
```

## 基本用法

### 1. 服务器配置

在`server.php`文件中配置服务器：

```php
use PhpSocketIO\SocketIOServer;

// 创建Socket.IO v4服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,  // 心跳间隔（毫秒）
    'pingTimeout'  => 20000,  // 心跳超时（毫秒）
    'maxPayload'   => 10485760, // 最大负载（字节）
    'workerCount'  => 1,       // worker数量，默认为1
]);
```

#### 配置说明

- **workerCount**: worker进程数量，默认为1。当设置为大于1时，必须通过setAdapter方法设置adapter。

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

// 设置集群适配器
$io->setAdapter(new ClusterAdapter($io->getServerManager()));
```

### 2. 事件处理

#### 连接事件

```php
// 注册根命名空间的连接事件处理器
$io->on('connection', function ($socket) use ($io) {
    // 连接成功时发送欢迎消息
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
    // 断开连接事件处理器
    $socket->on('disconnect', function () use ($socket) {
        // 可以在这里添加断开连接的清理逻辑
    });
});
```

#### 自定义事件

```php
$io->on('connection', function ($socket) use ($io) {
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
        // 向所有客户端群发消息
        $io->of('/chat')->emit('customEvent', $msg);
    });
});
```

#### ACK机制

```php
$io->on('connection', function ($socket) use ($io) {
    // ACK消息事件处理器
    $socket->on('ack', function ($msg, $callback) use ($socket) {
        if (is_callable($callback)) {
            $callback(['status' => 'ok', 'data' => $msg]);
        }
        return ['status' => 'ok', 'data' => $msg];
    });
    
    // 服务器ACK测试
    $socket->on('reqAck', function ($msg) use ($socket) {
        $socket->emitWithAck('ackResponse', 'Server ACK response', function($userdata){
            // 处理客户端的ACK回调
        });
    });
});
```

#### 二进制数据处理

```php
$io->on('connection', function ($socket) use ($io) {
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
});
```

### 3. 命名空间

```php
// 注册/test命名空间处理器
$io->on('connection', function ($socket) use ($io) {
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
}, '/test');
```

## 高级功能

### 1. 房间管理

```php
$io->on('connection', function ($socket) use ($io) {
    // 加入房间
    $socket->join('room1');
    
    // 离开房间
    $socket->leave('room1');
    
    // 向房间发送消息
    $socket->to('room1')->emit('message', 'Hello room1!');
    
    // 向除自己以外的房间成员发送消息
    $socket->to('room1')->broadcast->emit('message', 'Hello everyone in room1!');
});
```

### 2. 广播

```php
// 向所有客户端广播消息
$io->emit('broadcast', 'Hello everyone!');

// 向指定命名空间的所有客户端广播消息
$io->of('/chat')->emit('broadcast', 'Hello chat room!');

// 向指定房间的所有客户端广播消息
$io->to('room1')->emit('broadcast', 'Hello room1!');
```

### 3. 中间件

```php
// 注册中间件
$io->use(function ($socket, $next) {
    // 验证逻辑
    if (isset($socket->handshake['query']['token'])) {
        // 验证token
        $token = $socket->handshake['query']['token'];
        // 验证通过
        $next();
    } else {
        // 验证失败，拒绝连接
        $socket->disconnect(true);
    }
});
```

### 4. 集群模式

```php
// 设置跨进程适配器
$io->setAdapter('redis', [
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
]);
```

## API参考

### SocketIOServer 类

#### 构造函数

```php
public function __construct(string $listen, array $options = [])
```

- `$listen`: 监听地址，格式为 "address:port"
- `$options`: 配置选项
  - `pingInterval`: 心跳间隔（毫秒）
  - `pingTimeout`: 心跳超时（毫秒）
  - `maxPayload`: 最大负载（字节）
  - `ssl`: SSL配置

#### 方法

- `on($event, callable $handler, string $namespace = '/')`: 注册事件处理器
- `emit(string $event, mixed ...$args)`: 向所有客户端广播消息
- `broadcast(string $event, mixed ...$args)`: 向所有客户端广播消息（可排除指定socket）
- `to($room)`: 向指定房间发送消息
- `of($namespace)`: 获取指定命名空间的Socket.IO实例
- `join($room, Session $session = null)`: 将连接加入房间
- `leave($room, Session $session = null)`: 将连接从房间移除
- `use(callable $middleware, string $namespace = '/')`: 注册中间件
- `setAdapter($adapter, array $config = [])`: 设置跨进程适配器

### Socket 类

#### 方法

- `emit(string $event, mixed ...$args)`: 发送事件到客户端
- `emitBinary(string $event, mixed ...$args)`: 发送二进制事件到客户端
- `emitWithAck($event, ...$args)`: 带ACK的发送
- `join(string $room)`: 加入房间
- `leave(string $room)`: 离开房间
- `to($room)`: 向指定房间发送消息
- `broadcast()`: 广播事件到除了自己以外的其他连接
- `disconnect($close = false)`: 断开连接
- `timeout($value)`: 设置超时时间
- `except($rooms)`: 排除特定房间的广播修饰符

## 客户端示例

### 浏览器客户端

```html
<script src="https://cdn.socket.io/4.5.0/socket.io.min.js"></script>
<script>
    // 连接到Socket.IO服务器
    const socket = io('http://localhost:8088');
    
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
    
    // 断开连接事件
    socket.on('disconnect', () => {
        console.log('Disconnected from server');
    });
</script>
```

### Node.js客户端

```javascript
const io = require('socket.io-client');

// 连接到Socket.IO服务器
const socket = io('http://localhost:8088');

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
    const message = 'Hello from Node.js client';
    socket.emit('chat message', message);
}

// 断开连接事件
socket.on('disconnect', () => {
    console.log('Disconnected from server');
});
```

## 故障排除

### 常见问题

1. **服务器无法启动**
   - 检查端口是否被占用
   - 检查依赖是否安装正确
   - 检查PHP版本是否符合要求（PHP 7.4+）
2. **客户端无法连接**
   - 检查服务器是否正在运行
   - 检查网络连接是否正常
   - 检查客户端连接地址是否正确
3. **消息发送失败**
   - 检查事件名称是否正确
   - 检查消息格式是否符合要求
   - 检查服务器日志是否有错误信息
4. **二进制数据传输失败**
   - 检查数据大小是否超过最大负载限制
   - 检查客户端是否支持二进制数据传输

### 日志管理

服务器日志默认输出到控制台，你可以通过修改`server.php`文件来配置日志输出方式。

## 性能优化

1. **使用WebSocket传输**：WebSocket比HTTP轮询更高效，减少了网络开销
2. **合理设置心跳间隔**：根据实际需求调整心跳间隔，避免过于频繁的心跳
3. **使用房间和命名空间**：合理组织客户端，减少不必要的消息广播
4. **优化事件处理逻辑**：避免在事件处理器中执行耗时操作
5. **使用集群模式**：在高并发场景下，使用集群模式提高性能

## 安全注意事项

1. **验证客户端身份**：使用中间件验证客户端身份，防止未授权访问
2. **限制消息大小**：设置合理的最大负载限制，防止恶意客户端发送过大的消息
3. **过滤事件名称**：验证事件名称格式，防止恶意输入
4. **使用HTTPS**：在生产环境中使用HTTPS，加密传输数据
5. **定期清理会话**：避免会话泄漏，定期清理过期会话

## 版本历史

- **v1.0.0**：初始版本，支持Socket.IO v4协议
- **v1.1.0**：添加了二进制数据传输支持
- **v1.2.0**：添加了集群模式支持
- **v1.3.0**：优化了性能和稳定性

## 贡献指南

欢迎提交Issue和Pull Request来改进这个项目。在提交代码前，请确保：

1. 代码符合项目的代码风格
2. 添加了适当的测试
3. 文档已经更新

## 许可证

本项目使用MulanPSL-2.0许可证，详情请查看LICENSE文件。
