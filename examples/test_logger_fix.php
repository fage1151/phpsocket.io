<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use PhpSocketIO\Logger;
use Psr\Log\LogLevel;

// 创建 Logger 实例
$logger = new Logger(LogLevel::DEBUG);

echo "=== 测试 Logger::formatValue 修复 ===\n\n";

// 测试各种类型的输入
$testValues = [
    '普通字符串' => 'Hello World',
    '数字' => 123,
    '布尔值 true' => true,
    '布尔值 false' => false,
    'null' => null,
    '数组' => ['key' => 'value', 'number' => 42],
    '嵌套数组' => ['level1' => ['level2' => 'data']],
    '对象' => (object)['property' => 'value'],
];

foreach ($testValues as $name => $value) {
    echo "测试 - {$name}:\n";
    echo "  输入值: " . var_export($value, true) . "\n";
    
    // 使用反射调用私有方法
    $reflection = new ReflectionClass($logger);
    $method = $reflection->getMethod('formatValue');
    $method->setAccessible(true);
    
    $result = $method->invoke($logger, $value);
    
    echo "  输出结果: " . var_export($result, true) . "\n";
    echo "  输出类型: " . gettype($result) . "\n\n";
}

echo "=== Logger 修复测试完成！ ===\n";

// 现在测试完整的日志记录功能
echo "\n=== 测试完整的日志记录功能 ===\n\n";
$logger->debug('这是一个调试日志', ['boolVal' => true, 'arrayVal' => ['a' => 1]]);
$logger->info('这是一个信息日志', ['boolVal' => false]);
