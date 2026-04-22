<?php

declare(strict_types=1);

namespace PhpSocketIO;

use PhpSocketIO\Adapter\AdapterInterface;

final class ServerManager
{
    private const DEFAULT_PING_INTERVAL = 25000;
    private const DEFAULT_PING_TIMEOUT = 20000;
    
    private int $pingInterval;
    private int $pingTimeout;
    private ?AdapterInterface $adapter = null;
    private bool $clusterEnabled = false;
    private array $serverOptions = [];
    private bool $isRunning = false;
    private ?array $cors = null;
    private int $workerCount = 1;

    public function __construct(array $config = [])
    {
        $this->pingInterval = $config['pingInterval'] ?? self::DEFAULT_PING_INTERVAL;
        $this->pingTimeout = $config['pingTimeout'] ?? self::DEFAULT_PING_TIMEOUT;
        $this->workerCount = $config['workerCount'] ?? 1;
        $this->cors = $config['cors'] ?? null;
        $this->serverOptions = $config;
    }

    public function setConfig(array $config): void
    {
        $this->pingInterval = $config['pingInterval'] ?? $this->pingInterval;
        $this->pingTimeout = $config['pingTimeout'] ?? $this->pingTimeout;
        $this->workerCount = $config['workerCount'] ?? $this->workerCount;
        $this->cors = $config['cors'] ?? $this->cors;
        $this->serverOptions = array_merge($this->serverOptions, $config);
    }
    
    /**
     * 在启动服务器前验证配置
     */
    public function validateConfigBeforeStart(): void
    {
        $errors = $this->validateConfig();
        
        // 检查是否需要 adapter
        if ($this->workerCount > 1 && !$this->clusterEnabled) {
            $errors[] = 'When workerCount > 1, adapter must be set via setAdapter method';
        }
        
        if (!empty($errors)) {
            // 收集所有错误为一个消息
            $errorMsg = implode('; ', $errors);
            throw new \RuntimeException($errorMsg);
        }
    }

    public function setAdapter(AdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
        $this->clusterEnabled = true;
    }
    
    /**
     * 初始化 Adapter（必须在 Workerman 环境中调用）
     */
    public function initAdapter(): void
    {
        if ($this->adapter && $this->clusterEnabled) {
            $this->adapter->init();
        }
    }

    public function getAdapter(): ?AdapterInterface
    {
        return $this->isClusterEnabled() ? $this->adapter : null;
    }

    public function isClusterEnabled(): bool
    {
        return $this->clusterEnabled && $this->adapter !== null;
    }

    public function getConfig(): array
    {
        return [
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout,
            'clusterEnabled' => $this->clusterEnabled,
            'workerCount' => $this->workerCount,
            'adapter' => $this->adapter ? get_class($this->adapter) : null,
            'cors' => $this->cors,
            'serverOptions' => $this->serverOptions
        ];
    }

    public function getPingInterval(): int
    {
        return $this->pingInterval;
    }

    public function getPingTimeout(): int
    {
        return $this->pingTimeout;
    }

    public function getCors(): ?array
    {
        return $this->cors;
    }
    
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    public function setRunning(bool $running): void
    {
        $this->isRunning = $running;
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function validateConfig(): array
    {
        $errors = [];
        
        if ($this->pingInterval <= 0) {
            $errors[] = 'pingInterval must be greater than 0';
        }
        
        if ($this->pingTimeout <= 0) {
            $errors[] = 'pingTimeout must be greater than 0';
        }
        
        if ($this->pingInterval <= $this->pingTimeout) {
            $errors[] = 'pingInterval should be greater than pingTimeout';
        }
        
        return $errors;
    }

    public function getStatus(): array
    {
        return [
            'running' => $this->isRunning,
            'clusterEnabled' => $this->clusterEnabled,
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout,
            'workerCount' => $this->workerCount,
            'sessionCount' => count(Session::all()),
            'adapter' => $this->adapter ? get_class($this->adapter) : 'None',
            'configErrors' => $this->validateConfig()
        ];
    }

    public function shutdown(): void
    {
        if ($this->adapter && $this->clusterEnabled) {
            try {
                $this->adapter->close();
            } catch (\Exception $e) {
            }
        }
        
        $this->isRunning = false;
    }
}