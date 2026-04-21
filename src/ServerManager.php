<?php

declare(strict_types=1);

namespace PhpSocketIO;

use PhpSocketIO\Adapter\AdapterInterface;
use PhpSocketIO\Logger;

/**
 * 服务器管理器 - 管理Socket.IO服务器配置和状态
 */
class ServerManager
{
    private int $pingInterval      = 25000;
    private int $pingTimeout       = 20000;
    private ?AdapterInterface $adapter = null;
    private bool $clusterEnabled   = false;
    private array $serverOptions   = [];
    private bool $isRunning        = false;
    private $cors                  = null; // CORS配置
    private int $workerCount       = 1; // worker数量，默认为1

    /**
     * 设置服务器配置
     */
    public function setConfig(array $config): void
    {
        $this->pingInterval = $config['pingInterval'] ?? 25000;
        $this->pingTimeout  = $config['pingTimeout']  ?? 20000;
        $this->workerCount  = $config['workerCount']  ?? 1;
        $this->serverOptions = $config;
        
        // 设置CORS配置
        if (isset($config['cors'])) {
            $this->cors = $config['cors'];
        }
        
        // 如果worker数量大于1，必须设置adapter
        if ($this->workerCount > 1 && !$this->clusterEnabled) {
            throw new \RuntimeException('When workerCount > 1, adapter must be set via setAdapter method');
        }
    }

    /**
     * 设置跨进程适配器
     */
    public function setAdapter(AdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
        
        // 初始化适配器
        $this->adapter->init();
        $this->clusterEnabled = true;
    }

    /**
     * 获取集群适配器实例
     */
    public function getAdapter(): ?AdapterInterface
    {
        return $this->clusterEnabled ? $this->adapter : null;
    }

    /**
     * 检查是否启用集群模式
     */
    public function isClusterEnabled(): bool
    {
        return $this->clusterEnabled && $this->adapter !== null;
    }

    /**
     * 获取服务器配置
     */
    public function getConfig(): array
    {
        return [
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout,
            'clusterEnabled' => $this->clusterEnabled,
            'adapter' => $this->adapter ? get_class($this->adapter) : null,
            'serverOptions' => $this->serverOptions
        ];
    }

    /**
     * 获取ping间隔时间
     */
    public function getPingInterval(): int
    {
        return $this->pingInterval;
    }

    /**
     * 获取ping超时时间
     */
    public function getPingTimeout(): int
    {
        return $this->pingTimeout;
    }

    /**
     * 获取CORS配置
     */
    public function getCors()
    {
        return $this->cors;
    }
    
    /**
     * 获取worker数量
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * 设置服务器运行状态
     */
    public function setRunning(bool $running): void
    {
        $this->isRunning = $running;
    }

    /**
     * 检查服务器是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * 验证配置是否有效
     */
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

    /**
     * 获取服务器状态信息
     */
    public function getStatus(): array
    {
        $sessions = Session::all();
        $sessionCount = count($sessions);
        
        return [
            'running' => $this->isRunning,
            'clusterEnabled' => $this->clusterEnabled,
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout,
            'workerCount' => $this->workerCount,
            'sessionCount' => $sessionCount,
            'adapter' => $this->adapter ? get_class($this->adapter) : 'None',
            'configErrors' => $this->validateConfig()
        ];
    }

    /**
     * 停止服务器运行时清理资源
     */
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