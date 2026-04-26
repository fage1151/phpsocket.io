<?php

declare(strict_types=1);

namespace PhpSocketIO;

use PhpSocketIO\Enum\LogLevelPriority;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 兼容日志记录器 - PHP 8.1+ 深度优化版本
 *
 * @package PhpSocketIO
 */
final class Logger extends AbstractLogger
{
    private LogLevelPriority $currentLevel;
    private mixed $handler = null;

    public function __construct(string $level = LogLevel::INFO)
    {
        $this->currentLevel = LogLevelPriority::fromName($level);
    }

    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    public function setLevel(string $level): void
    {
        $this->currentLevel = LogLevelPriority::fromName($level);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelStr = (string)$level;
        if (!LogLevelPriority::shouldLog($levelStr, $this->currentLevel)) {
            return;
        }

        $formatted = $this->formatMessage($levelStr, (string)$message, $context);

        $this->handler ? ($this->handler)($levelStr, $formatted, $context) : $this->defaultHandler($formatted);
    }

    private function formatMessage(string $level, string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = $this->formatValue($value);
        }

        $processedMessage = strtr($message, $replace);
        $datetime = date('Y-m-d H:i:s');
        return "[{$datetime}] [{$level}] {$processedMessage}";
    }

    private function formatValue(mixed $value): string
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value);
            // 如果 json_encode 失败，返回一个安全的字符串
            return $encoded !== false ? $encoded : '[不可序列化的对象]';
        }

        // 处理布尔值和其他标量类型
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    private function defaultHandler(string $message): void
    {
        if (defined('STDOUT') && is_resource(\STDOUT)) {
            fwrite(\STDOUT, $message . PHP_EOL);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
