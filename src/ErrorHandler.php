<?php

declare(strict_types=1);

namespace PhpSocketIO;

use PhpSocketIO\Exceptions\SocketIOException;
use PhpSocketIO\Exceptions\ConnectionException;
use PhpSocketIO\Exceptions\ProtocolException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Socket.IO 统一错误处理类
 *
 * @package PhpSocketIO
 */
final class ErrorHandler
{
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * 设置日志记录器
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 处理异常并记录日志
     */
    public function handleException(Throwable $exception, array $context = []): void
    {
        $level = $this->determineLogLevel($exception);
        $message = $this->formatExceptionMessage($exception);

        $logContext = array_merge($context, [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->logger?->log($level, $message, $logContext);
    }

    /**
     * 根据异常类型确定日志级别
     */
    private function determineLogLevel(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ConnectionException => LogLevel::ERROR,
            $exception instanceof ProtocolException => LogLevel::WARNING,
            default => LogLevel::ERROR
        };
    }

    /**
     * 格式化异常消息
     */
    private function formatExceptionMessage(Throwable $exception): string
    {
        return sprintf(
            '[%s] %s',
            $exception::class,
            $exception->getMessage()
        );
    }

    /**
     * 安全地记录错误（不会抛出异常）
     */
    public function safeLog(string $message, array $context = [], string $level = LogLevel::ERROR): void
    {
        try {
            $this->logger?->log($level, $message, $context);
        } catch (Throwable) {
            // 日志失败也不应该阻止程序继续执行
        }
    }
}
