<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpSocketIO\PacketParser;

// 测试数据包 - 可能用户的输入有些小错误，让我们测试几个可能的格式
$testPackets = [
    // 可能的格式 1: 42/chat,["ack","string"]
    '42/chat,["ack","string"]',
    // 可能的格式 2: 42/chat,1["ack","string"] (带 ackId)
    '42/chat,1["ack","string"]',
    // 可能的格式 3: 用户输入的 "42/chat,4[\"ack\",\"string\"]"
    '42/chat,4["ack","string"]',
];

echo "=== PacketParser 测试 ===\n\n";

foreach ($testPackets as $i => $packet) {
    echo "测试数据包 " . ($i + 1) . ": " . $packet . "\n";

    // 第一步：解析 Engine.IO 数据包
    $enginePacket = PacketParser::parseEngineIOPacket($packet);
    echo "Engine.IO 解析结果: " . json_encode($enginePacket, JSON_UNESCAPED_UNICODE) . "\n";

    if ($enginePacket && $enginePacket['type'] === 'MESSAGE') {
        // 第二步：解析 Socket.IO 数据包
        $socketPacket = PacketParser::parseSocketIOPacket($enginePacket['data']);
        echo "Socket.IO 解析结果: " . json_encode($socketPacket, JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n";
}
