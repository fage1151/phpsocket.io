<?php

namespace PhpSocketIO;

/**
 * 中间件处理器 - 处理连接和事件中间件
 */
class MiddlewareHandler
{
    private $connectionMiddlewares = []; // 全局连接中间件
    private $eventMiddlewares = []; // 全局事件中间件
    private $namespaceMiddlewares = []; // 命名空间中间件

    /**
     * 添加连接中间件
     */
    public function addConnectionMiddleware(callable $middleware): self
    {
        $this->connectionMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * 添加事件中间件
     */
    public function addEventMiddleware(callable $middleware): self
    {
        $this->eventMiddlewares[] = $middleware;
        return $this;
    }

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
     * 执行连接中间件
     */
    public function executeConnectionMiddlewares(array $request, array &$response): bool
    {
        foreach ($this->connectionMiddlewares as $middleware) {
            $result = call_user_func($middleware, $request, $response);
            if ($result === false) {
                return false; // 中间件拒绝连接
            }
        }
        return true;
    }

    /**
     * 执行事件中间件
     */
    public function executeEventMiddlewares(array $socket, array &$packet): bool
    {
        foreach ($this->eventMiddlewares as $middleware) {
            $result = call_user_func($middleware, $socket, $packet);
            if ($result === false) {
                return false; // 中间件阻止事件
            }
        }
        return true;
    }

    /**
     * 执行命名空间中间件 (Socket.IO v4标准)
     */
    public function executeNamespaceMiddlewares(string $namespace, $socket, callable $next): bool
    {
        $middlewares = $this->namespaceMiddlewares[$namespace] ?? [];
        
        if (empty($middlewares)) {
            $next();
            return true;
        }
        
        $index = 0;
        $executeNext = function() use (&$index, $middlewares, $socket, &$executeNext, $next) {
            if ($index < count($middlewares)) {
                $middleware = $middlewares[$index];
                $index++;
                $middleware($socket, $executeNext);
            } else {
                $next();
            }
        };
        
        $executeNext();
        return true;
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
        $this->connectionMiddlewares = [];
        $this->eventMiddlewares = [];
        $this->namespaceMiddlewares = [];
    }

    /**
     * 获取中间件数量
     */
    public function getMiddlewareCount(): array
    {
        $count = [
            'connection' => count($this->connectionMiddlewares),
            'event' => count($this->eventMiddlewares)
        ];
        
        foreach ($this->namespaceMiddlewares as $namespace => $middlewares) {
            $count["namespace_{$namespace}"] = count($middlewares);
        }
        
        return $count;
    }

    /**
     * 检查是否有中间件
     */
    public function hasMiddlewares(): bool
    {
        return !empty($this->connectionMiddlewares) || 
               !empty($this->eventMiddlewares) || 
               !empty($this->namespaceMiddlewares);
    }
}