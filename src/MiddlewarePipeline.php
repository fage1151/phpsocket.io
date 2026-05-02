<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * 中间件管道类 - 统一的中间件链构建和执行
 *
 * Socket.IO v4 中间件签名规范：
 * - 全局/命名空间级别中间件：function (Socket $socket, callable $next) { ... }
 *   仅在客户端连接到命名空间时执行，可用于身份验证等
 * - Socket 实例级别中间件：function (array $packet, callable $next) { ... }
 *   对每个接收的事件包执行，可用于事件过滤等
 *
 * @package PhpSocketIO
 */
final class MiddlewarePipeline
{
    /**
     * 构建中间件管道并执行
     *
     * @param array<callable> $middlewares 中间件数组
     * @param callable $finalHandler 最终处理回调
     * @param mixed ...$args 传递给中间件的参数（不包含 $next）
     * @return mixed
     */
    public static function execute(array $middlewares, callable $finalHandler, mixed ...$args): mixed
    {
        if (empty($middlewares)) {
            return $finalHandler(...$args);
        }

        // 直接的方法：依次调用中间件
        $next = function () use ($middlewares, $finalHandler, $args) {
            return self::execute(array_slice($middlewares, 1), $finalHandler, ...$args);
        };

        $middleware = $middlewares[0];
        $allArgs = $args;
        $allArgs[] = $next;
        return $middleware(...$allArgs);
    }
}
