<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * 中间件管道类 - 统一的中间件链构建和执行
 *
 * @package PhpSocketIO
 */
final class MiddlewarePipeline
{
    /**
     * 构建中间件管道并执行
     *
     * 中间件的签名：
     * - 全局/命名空间级别中间件：function ($socket, $packet, $next) { ... }
     * - Socket 实例级别中间件：function ($packet, $next) { ... }
     *
     * $next 会被正确地作为最后一个参数传递给每个中间件
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
