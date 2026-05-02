<?php

declare(strict_types=1);

namespace PhpSocketIO\Event;

/**
 * 中间件管道类 - 统一的中间件链构建和执行
 *
 * Socket.IO v4 中间件签名规范：
 * - 全局/命名空间级别中间件：function (Socket $socket, callable $next) { ... }
 *   仅在客户端连接到命名空间时执行，可用于身份验证等
 * - Socket 实例级别中间件：function (array $packet, callable $next) { ... }
 *   对每个接收的事件包执行，可用于事件过滤等
 *
 * @package PhpSocketIO\Event
 */
final class MiddlewarePipeline
{
    public static function execute(array $middlewares, callable $finalHandler, mixed ...$args): mixed
    {
        if (empty($middlewares)) {
            return $finalHandler(...$args);
        }

        $next = function () use ($middlewares, $finalHandler, $args) {
            return self::execute(array_slice($middlewares, 1), $finalHandler, ...$args);
        };

        $middleware = $middlewares[0];
        $allArgs = $args;
        $allArgs[] = $next;
        return $middleware(...$allArgs);
    }
}
