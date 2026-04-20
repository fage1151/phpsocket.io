<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Workerman\Connection\TcpConnection;

// ========================================
//  ★ 主服务器启动脚本
// ========================================

// 创建Socket.IO v4服务器实例
try {
    $io = new SocketIOServer('0.0.0.0:8088', [
        'pingInterval' => 25000,
        'pingTimeout'  => 20000,
        'maxPayload'   => 10485760, // 10MB 最大负载
    ]);
} catch (Exception $e) {
    echo "Error creating server: " . $e->getMessage() . "\n";
    exit(1);
}

// 注册根命名空间的连接事件处理器
$io->on('connection', function ($socket) use ($io) {
    // 连接成功时发送欢迎消息
    $socket->emit('welcome', 'Welcome to Socket.IO server!');
    
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
        return ['status' => 'ok'];
    });

    // 二进制数据处理
    $socket->on('buffer', function ($msg) use ($socket) {
        var_dump($msg);
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

    // 二进制数据事件处理器
    $socket->on('blob', function ($data) use ($socket) {
        if (is_string($data)) {
            $dataSize = strlen($data);
            $socket->emit('blob', ['status' => 'received', 'size' => $dataSize]);
        }
    });

    // ping事件处理
    $socket->on('ping', function () use ($socket) {
        $socket->emit('pong');
    });

    // ACK超时测试
    $socket->on('ackTimeout', function ($timeout) use ($socket) {
        usleep($timeout * 1000); // 模拟超时
        // 不发送响应，让客户端超时
    });

    // 双向ACK测试
    $socket->on('twoWay', function ($data) use ($socket) {
        $socket->emit('twoWay', 'Server response', function($clientResponse) use ($socket) {
            $socket->emit('twoWayBack', 'Final response: ' . $clientResponse);
        });
    });

    // 二进制事件测试
    $socket->on('binaryEvent', function ($data) use ($socket) {
        $socket->emit('binaryEvent', $data);
    });

    // 请求二进制数据
    $socket->on('reqBinary', function ($data) use ($socket) {
        $binaryData = "Hello binary world!";
        $socket->emitBinary('binaryResponse', $data);
    });

    // 类型化数组处理
    $socket->on('typedArray', function ($data) use ($socket) {
        $socket->emit('binaryResponse', ['status' => 'ok', 'data' => $data]);
    });

    // 多ACK测试
    for ($i = 1; $i <= 3; $i++) {
        $eventName = "ack{$i}";
        $socket->on($eventName, function ($data) use ($socket, $i) {
            $socket->emit("ack{$i}", "Response for ack{$i}");
        });
    }

    // 断开连接事件处理器
    $socket->on('disconnect', function () use ($socket) {
        // 可以在这里添加断开连接的清理逻辑
    });
    
}, '/chat');

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

Worker::runAll();