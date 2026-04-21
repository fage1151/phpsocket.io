<?php

declare(strict_types=1);

namespace PhpSocketIO;

final class MiddlewareHandler
{
    private array $namespaceMiddlewares = [];

    public function use(callable $middleware, string $namespace = '/'): self
    {
        if (!isset($this->namespaceMiddlewares[$namespace])) {
            $this->namespaceMiddlewares[$namespace] = [];
        }
        $this->namespaceMiddlewares[$namespace][] = $middleware;
        return $this;
    }

    public function getNamespaceMiddlewares(string $namespace = '/'): array
    {
        return $this->namespaceMiddlewares[$namespace] ?? [];
    }

    public function clearMiddlewares(): void
    {
        $this->namespaceMiddlewares = [];
    }

    public function hasMiddlewares(): bool
    {
        return !empty($this->namespaceMiddlewares);
    }
}