<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use PhpSocketIO\SocketIOServer;
use Workerman\Connection\TcpConnection;

// ========================================
//  ★ 主服务器启动脚本
// ========================================

// 创建Socket.IO v4服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'pingInterval' => 25000,
    'pingTimeout'  => 20000,
]);

// 注册根命名空间的连接事件处理器
$io->on('connection', function ($socket) use ($io) {
    echo "[/] 连接: " . $socket->getId() . " transport=" . $socket->getTransport() . "\n";
    echo "[socket] 开始注册事件监听器\n";
    
    // 聊天消息事件处理器
    echo "[socket] 正在注册chat message事件监听器\n";
    $socket->on('chat message', function ($msg) use ($socket) {
        echo "[/] chat message事件触发，接收消息: {$msg}\n";
        $socket->broadcast->emit('chat message', $msg);
    });
    echo "[socket] chat message事件监听器注册完成\n";

    // 通用消息事件处理器
    echo "[socket] 正在注册message事件监听器\n";
    $socket->on('message', function ($msg) use ($socket) {
        echo "[/] message事件触发，接收消息: {$msg}\n";
        $socket->emit('message', $msg);
    });
    echo "[socket] message事件监听器注册完成\n";

    // 通用消息事件处理器
    echo "[socket] 正在注册customEvent事件监听器\n";
    $socket->on('customEvent', function ($msg) use ($socket, $io) {
        echo "[/] customEvent事件触发，接收消息: {$msg}\n";
        $io->emit('message', $msg);
    });
    echo "[socket] message事件监听器注册完成\n";
    
    // ACK消息事件处理器
    $socket->on('ack', function ($msg, $callback) use ($socket) {
        echo "[/] ack事件触发，接收消息: {$msg}\n";
        // 检查是否存在回调函数
        if (is_callable($callback)) {
            echo "[/] 发送ACK响应: status=ok, data={$msg}\n";
            $callback(['status' => 'ok', 'data' => $msg]);
        } else {
            echo "[/] 没有提供回调函数，返回默认响应\n";
            return ['status' => 'ok', 'data' => $msg];
        }
    });

    // ACK消息事件处理器
    $socket->on('reqAck', function ($msg) use ($socket) {
            echo "[/] reqAck事件触发，接收消息: {$msg}\n";
            $socket->emitWithAck('ackResponse', 'response', function($userdata){
                echo "收到ACK\n";
            });
            return ['status' => 'ok', 'data' => $msg];
    });


    // ACK消息事件处理器
    $socket->on('buffer', function ($msg) use ($socket) {
        echo "[/] buffer事件触发，接收消息: {$msg}\n";
        // 检查是否存在回调函数
            echo "[/] 发送buffer响应: status=ok, data={$msg}\n";
            var_dump(!ctype_print($msg));
            $socket->emitBinary("binary", $msg, ["req" => 'test']);
        }
    );
    echo "[socket] buffer事件监听器注册完成\n";
    
    // 二进制数据事件处理器
    $socket->on('blob', function ($data) use ($socket) {
        echo "[/] blob事件触发\n";
        if (is_string($data)) {
            $dataSize = strlen($data);
            echo "[/] 接收到二进制数据: 大小={$dataSize} 字节\n";
            echo "[/] 数据内容(前100字节): " . substr($data, 0, 100) . "\n";
            
            // 回显数据给客户端
            $socket->emit('blob', ['status' => 'received', 'size' => $dataSize]);
        } else {
            echo "[/] 接收到非二进制数据: " . json_encode($data) . "\n";
        }
    });
    echo "[socket] blob事件监听器注册完成\n";

    // 断开连接事件处理器
    $socket->on('disconnect', function () use ($socket) {
        echo "[/] 断开: " . $socket->getId() . "\n";
    });
    
}, '/chat');



Worker::runAll();