<?php

namespace PhpSocketIO;

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

    /**
     * 设置服务器配置
     */
    public function setConfig(array $config): void
    {
        $this->pingInterval = $config['pingInterval'] ?? 25000;
        $this->pingTimeout  = $config['pingTimeout']  ?? 20000;
        $this->serverOptions = $config;
        
        // 设置CORS配置
        if (isset($config['cors'])) {
            $this->cors = $config['cors'];
        }

        // 设置适配器
        if (isset($config['adapter'])) {
            $this->setAdapter($config['adapter']);
        }
    }

    /**
     * 设置跨进程适配器
     */
    public function setAdapter($adapter, array $config = []): void
    {
        if ($adapter instanceof AdapterInterface) {
            $this->adapter = $adapter;
        } elseif (is_string($adapter) && class_exists($adapter)) {
            $this->adapter = new $adapter($this);
        } else {
            // 使用默认的ClusterAdapter
            $this->adapter = new ClusterAdapter($this);
        }
        
        // 初始化适配器
        $this->adapter->init($config);
        $this->clusterEnabled = true;
        
        echo "[cluster] adapter initialized successfully\n";
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
                echo "[cluster] adapter closed successfully\n";
            } catch (\Exception $e) {
                echo "[error] Failed to close adapter: " . $e->getMessage() . "\n";
            }
        }
        
        $this->isRunning = false;
        echo "[server] Server shutdown completed\n";
    }

    /**
     * 重新加载配置
     */
    public function reloadConfig(array $newConfig): bool
    {
        try {
            $oldConfig = $this->getConfig();
            $this->setConfig($newConfig);
            
            echo "[server] Configuration reloaded\n";
            echo "[server] Changes: " . json_encode(array_diff_assoc($newConfig, $oldConfig)) . "\n";
            
            return true;
        } catch (\Exception $e) {
            echo "[error] Failed to reload configuration: " . $e->getMessage() . "\n";
            return false;
        }
    }
}