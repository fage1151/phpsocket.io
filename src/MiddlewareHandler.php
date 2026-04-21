<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * 中间件处理器 - 处理连接和事件中间件
 */
class MiddlewareHandler
{
    private $namespaceMiddlewares = []; // 命名空间中间件

    /**
     * 添加命名空间中间件 (Socket.IO v4标准)
     */
    public function use(callable $middleware, string $namespace = '/'): self
    {
        if (!isset($this->namespaceMiddlewares[$namespace])) {
            $this->namespaceMiddlewares[$namespace] = [];
        }
        $this->namespaceMiddlewares[$namespace][] = $middleware;
        return $this;
    }



    /**
     * 获取命名空间中间件
     */
    public function getNamespaceMiddlewares(string $namespace = '/'): array
    {
        return $this->namespaceMiddlewares[$namespace] ?? [];
    }

    /**
     * 清空所有中间件
     */
    public function clearMiddlewares(): void
    {
        $this->namespaceMiddlewares = [];
    }

    /**
     * 检查是否有中间件
     */
    public function hasMiddlewares(): bool
    {
        return !empty($this->namespaceMiddlewares);
    }
}