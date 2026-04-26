<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpSocketIO\PacketParser;

echo "=== 调试二进制事件解析 ===" . PHP_EOL . PHP_EOL;

$testPacket = '451-/chat,["file",{"name":"auto_search_item2.html","data":{"_placeholder":true,"num":0}}]';

echo "原始数据包: " . PHP_EOL . $testPacket . PHP_EOL . PHP_EOL;

// 测试 PacketParser 解析
try {
    $packet = PacketParser::parseSocketIOPacket($testPacket);
    if ($packet) {
        echo "解析成功!" . PHP_EOL;
        echo "解析结果:" . PHP_EOL;
        var_dump($packet);

        if (isset($packet['type']) && $packet['type'] === 'BINARY_EVENT') {
            echo PHP_EOL . "✅ 是二进制事件类型" . PHP_EOL;
            echo "binaryCount: " . ($packet['binaryCount'] ?? '未设置') . PHP_EOL;
            echo "namespace: " . ($packet['namespace'] ?? '/') . PHP_EOL;
            echo "event: " . ($packet['event'] ?? '未设置') . PHP_EOL;
        }
    } else {
        echo "❌ 解析失败" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "解析错误: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== 测试 replaceBinaryPlaceholders ===" . PHP_EOL;

// 模拟二进制附件
$packet = [
    'type' => 'BINARY_EVENT',
    'binaryCount' => 1,
    'namespace' => '/chat',
    'event' => 'file',
    'data' => [
        ['name' => 'auto_search_item2.html', 'data' => ['_placeholder' => true, 'num' => 0]]
    ]
];

$binaryAttachments = ['模拟二进制文件内容'];

$replacedPacket = PacketParser::replaceBinaryPlaceholders($packet, $binaryAttachments);

echo "替换后的数据包:" . PHP_EOL;
var_dump($replacedPacket);
