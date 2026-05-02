<?php

declare(strict_types=1);

namespace PhpSocketIO\Support;

use PhpSocketIO\Exceptions\SocketIOException;
use PhpSocketIO\Exceptions\ConnectionException;
use PhpSocketIO\Exceptions\ProtocolException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Socket.IO 统一错误处理类
 *
 * @package PhpSocketIO\Support
 */
final class ErrorHandler
{
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

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

    private function determineLogLevel(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ConnectionException => LogLevel::ERROR,
            $exception instanceof ProtocolException => LogLevel::WARNING,
            default => LogLevel::ERROR
        };
    }

    private function formatExceptionMessage(Throwable $exception): string
    {
        return sprintf(
            '[%s] %s',
            $exception::class,
            $exception->getMessage()
        );
    }

    public function safeLog(string $message, array $context = [], string $level = LogLevel::ERROR): void
    {
        try {
            $this->logger?->log($level, $message, $context);
        } catch (Throwable) {
        }
    }
}
