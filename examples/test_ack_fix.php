<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpSocketIO\SocketIOServer;
use PhpSocketIO\PacketParser;
use PhpSocketIO\Session;
use Psr\Log\LogLevel;

// 创建服务器实例
$io = new SocketIOServer('0.0.0.0:8088', [
    'maxPayload' => 10485760,
    'logLevel' => LogLevel::DEBUG
]);

// 注册和我们 server.php 中一样的事件处理器
$io->of('/chat')->on('connection', function ($socket) use ($io) {
    echo "=== 模拟连接事件 ===\n";
    
    $socket->on('ack', function ($msg, $callback = null) use ($socket) {
        echo "=== ack 事件被调用！ ===\n";
        echo "  - msg: " . var_export($msg, true) . "\n";
        echo "  - callback is_callable: " . (is_callable($callback) ? '是' : '否') . "\n";
        
        if (is_callable($callback)) {
            echo "  - 正在调用 callback 回复...\n";
            $callback(['status' => 'ok', 'data' => $msg]);
            echo "  - callback 调用完成！\n";
        }
    });
});

// 现在让我们模拟一个会话和数据包
echo "\n=== 开始模拟处理数据包 ===\n";

// 创建一个模拟会话
$session = new Session('test_sid_12345');
$session->send = function($data) {
    echo "  \n=== 模拟发送数据给客户端 ===\n";
    echo "  发送的数据: " . $data . "\n";
    echo "  === 发送完成 ===\n\n";
};

// 模拟的数据包：42/chat,4["ack","string"]
$testPacket = '42/chat,4["ack","string"]';

echo "测试的数据包: " . $testPacket . "\n\n";

// 解析 Engine.IO 数据包
$enginePacket = PacketParser::parseEngineIOPacket($testPacket);
echo "Engine.IO 解析结果: " . json_encode($enginePacket, JSON_UNESCAPED_UNICODE) . "\n\n";

if ($enginePacket && $enginePacket['type'] === 'MESSAGE') {
    // 解析 Socket.IO 数据包
    $socketPacket = PacketParser::parseSocketIOPacket($enginePacket['data']);
    echo "Socket.IO 解析结果: " . json_encode($socketPacket, JSON_UNESCAPED_UNICODE) . "\n\n";
    
    if ($socketPacket && $socketPacket['type'] === 'EVENT') {
        $namespace = $socketPacket['namespace'] ?? '/';
        $eventName = $socketPacket['event'] ?? '';
        $eventData = $socketPacket['data'] ?? [];
        $ackId = $socketPacket['id'] ?? null;
        
        echo "  事件信息:\n";
        echo "  - 命名空间: " . $namespace . "\n";
        echo "  - 事件名称: " . $eventName . "\n";
        echo "  - 事件数据: " . json_encode($eventData, JSON_UNESCAPED_UNICODE) . "\n";
        echo "  - ACK ID: " . var_export($ackId, true) . "\n\n";
        
        // 现在直接调用 processEventHandlers 来处理
        $eventHandler = $io->getEventHandler();
        $socket = $io->getOrCreateSocket($session, $namespace);
        
        // 使用反射调用私有方法 processEventHandlers
        $reflection = new ReflectionClass($io);
        $method = $reflection->getMethod('processEventHandlers');
        $method->setAccessible(true);
        
        echo "=== 调用 processEventHandlers ===\n";
        $result = $method->invoke($io, $session, $namespace, $eventName, $eventData, $socket, $ackId);
        echo "processEventHandlers 返回值: " . var_export($result, true) . "\n";
    }
}

echo "\n=== 测试完成 ===\n";
