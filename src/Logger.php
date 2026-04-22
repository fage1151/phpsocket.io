<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 兼容日志记录器
 * 提供基本的日志功能，可自定义日志处理器
 */
class Logger extends AbstractLogger
{
    /** @var array 日志级别映射 */
    private const LEVEL_MAP = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var string 当前日志级别 */
    private $currentLevel;

    /** @var callable|null 自定义日志处理器 */
    private $handler;

    /**
     * 构造函数
     * @param string $level 最小日志级别，默认为 INFO
     */
    public function __construct(string $level = LogLevel::INFO)
    {
        $this->currentLevel = $level;
    }

    /**
     * 设置自定义日志处理器
     * @param callable $handler 处理器函数，接收 (level, message, context)
     */
    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * 设置日志级别
     * @param string $level PSR-3 日志级别
     */
    public function setLevel(string $level): void
    {
        if (isset(self::LEVEL_MAP[$level])) {
            $this->currentLevel = $level;
        }
    }

    /**
     * 记录日志
     * @param mixed $level PSR-3 日志级别
     * @param string|\Stringable $message 日志消息
     * @param array $context 上下文数据
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $formatted = $this->formatMessage($level, (string)$message, $context);

        if ($this->handler) {
            call_user_func($this->handler, $level, $formatted, $context);
        } else {
            $this->defaultHandler($formatted);
        }
    }

    /**
     * 检查是否应该记录该级别的日志
     */
    private function shouldLog($level): bool
    {
        if (!isset(self::LEVEL_MAP[$level])) {
            return false;
        }
        return self::LEVEL_MAP[$level] >= self::LEVEL_MAP[$this->currentLevel];
    }

    /**
     * 格式化日志消息
     */
    private function formatMessage(string $level, string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = $this->formatValue($val);
        }
        $message = strtr($message, $replace);

        $datetime = date('Y-m-d H:i:s');
        return "[{$datetime}] [{$level}] {$message}";
    }

    /**
     * 格式化值
     */
    private function formatValue($value): string
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string)$value;
    }

    /**
     * 默认日志处理器，输出到标准错误或文件
     */
    private function defaultHandler(string $message): void
    {
        if (defined('STDOUT')) {
            fwrite(STDOUT, $message . PHP_EOL);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
