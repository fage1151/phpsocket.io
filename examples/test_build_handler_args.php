<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpSocketIO\EventHandler;

// 模拟事件处理器 - 和 server.php 中一样的
$testHandler = function (mixed $msg, mixed $callback = null) {
    echo "=== testHandler 被调用 ===\n";
    echo "  - msg: " . var_export($msg, true) . "\n";
    echo "  - callback is_callable: " . (is_callable($callback) ? '是' : '否') . "\n";
    
    if (is_callable($callback)) {
        echo "  - 正在调用 callback...\n";
        $callback(['status' => 'ok', 'data' => $msg]);
    }
};

// 模拟 Socket 数组
$socket = [
    'id' => 'test_sid',
    'namespace' => '/chat'
];

// 事件数据
$eventData = ['string'];

// 命名空间
$namespace = '/chat';

// 模拟 ACK 回调
$ackCallback = function (mixed ...$data) {
    echo "\n=== ackCallback 被调用！ ===\n";
    echo "  - 回复数据: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
};

echo "=== 测试 buildHandlerArguments ===\n\n";

// 调用我们修复后的方法
$callArgs = EventHandler::buildHandlerArguments(
    $testHandler,
    $socket,
    $eventData,
    $namespace,
    4,  // ackId
    $ackCallback
);

echo "构建的 callArgs: " . json_encode($callArgs, JSON_UNESCAPED_UNICODE) . "\n";
echo "callArgs 长度: " . count($callArgs) . "\n\n";

// 现在调用处理器！
echo "=== 现在调用事件处理器 ===\n\n";
call_user_func_array($testHandler, $callArgs);

echo "\n=== 测试完成 ===\n";
