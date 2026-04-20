<?php

namespace PhpSocketIO;

use Exception;

/**
 * Socket.IO 会话管理器
 * @package SocketIO
 */
class Session
{
    // 会话属性
    public string $sid;              // 会话ID (24字符十六进制)
    public string $transport    = 'polling'; // 传输类型: polling|websocket
    public $connection   = null;      // 连接对象
    public array $pollingQueue = [];        // 轮询消息队列
    public array $namespaces   = [];        // 命名空间授权状态
    public int $lastPong;                 // 最后心跳时间
    public int $lastPing;                 // 最后发送ping时间
    public bool $isWs         = false;     // 是否WebSocket连接
    public int $createdAt;                // 创建时间戳
    public int $messageCount = 0;         // 消息计数
    public int $errorCount   = 0;         // 错误计数
    public array $ackCallbacks = [];        // ACK回调函数存储
    public $handshake    = null;      // 握手信息
    public array $data         = [];        // 任意数据对象 (v4.0.0+)
    public array $pendingBinaryAttachments = []; // 待处理的二进制附件
    public $pendingBinaryPlaceholder = null; // 待处理的二进制占位符包
    public int $pendingBinaryCount = 0;  // 需要等待的二进制附件数量

    // 静态会话管理
    private static array $sessions    = [];  // 活跃会话池
    private static int $maxSessions = 10000; // 最大会话限制，避免内存泄漏
    private static int $sessionTtl  = 86400; // 会话过期时间(24小时)
    private static array $cache     = [];    // 会话缓存
    private static int $cacheSize   = 1000;  // 缓存大小限制

    /**
     * 创建新会话（带格式验证和内存管理）
     * @param string $sid 会话ID (24字符十六进制)
     * @throws InvalidArgumentException 当会话ID无效或超过限制时
     */
    public function __construct(string $sid)
    {
        // 会话ID格式验证（24字符十六进制格式）
        if (!preg_match('/^[a-f0-9]{24}$/', $sid)) {
            throw new \InvalidArgumentException("Invalid session ID format: {$sid}");
        }
        
        // 检查会话数量限制
        if (count(self::$sessions) >= self::$maxSessions) {
            self::cleanupOldestSessions(100); // 清理最旧的100个会话
        }
        
        // 初始化会话属性
        $this->sid = $sid;
        $this->lastPong = time();
        $this->lastPing = 0; // 初始化为0，确保第一次心跳检查就发送ping
        $this->createdAt = time();
        self::$sessions[$sid] = $this;
        
        // 清理缓存
        self::cleanupCache();
        
        echo "[session] created sid={$sid} total=" . count(self::$sessions) . "\n";
    }

    /**
     * 获取会话（带缓存优化）
     */
    public static function get(string $sid): ?self
    {
        if (isset(self::$cache[$sid])) return self::$cache[$sid];
        return self::$cache[$sid] = self::$sessions[$sid] ?? null;
    }

    public static function remove(string $sid): void
    {
        unset(self::$sessions[$sid]);
        unset(self::$cache[$sid]);
    }

    public static function all(): array
    {
        return self::$sessions;
    }

    public function enqueue(string $packet): void
    {
        $this->pollingQueue[] = $packet;
    }

    public function flush(): array
    {
        $msgs = $this->pollingQueue;
        $this->pollingQueue = [];
        return $msgs;
    }

    public function updateLastPong(): void
    {
        $this->lastPong = time();
    }

    public function send(string $packet): bool
    {
        // 智能判断WebSocket状态：如果connection对象存在且有send方法，自动识别为WebSocket模式
        $shouldUseWebSocket = $this->connection && method_exists($this->connection, 'send');
        
        if ($shouldUseWebSocket) {
            // 如果isWs为false但connection有效，自动纠正状态
            if (!$this->isWs) {
                $this->isWs = true;
                $this->transport = 'websocket';
            }
            
            // 检查连接是否有效
            try {
                // 使用HttpRequestHandler中的标准WebSocket发送方法
                return \PhpSocketIO\HttpRequestHandler::sendWsFrame($this->connection, $packet, false);
            } catch (Exception $e) {
                echo "[session send] WebSocket发送异常: " . $e->getMessage() . "\n";
                return false;
            }
        } else {
            $this->enqueue($packet);
            return true;
        }
    }

    /**
     * 发送二进制数据（优化版，支持Workerman WebSocket二进制帧）
     */
    public function sendBinary(string $binaryData): void
    {
        if ($this->isWs && $this->connection) {
            // WebSocket连接：手动控制帧类型
            // 1. 保存当前WebSocket帧类型
            $originalType = $this->connection->websocketType ?? "\x81";
            
            // 2. 切换到二进制帧类型
            $this->connection->websocketType = "\x82"; // 二进制帧
            
            // 3. 发送二进制数据
            try {
                $this->connection->send($binaryData);
            } finally {
                // 4. 恢复为文本帧类型
                $this->connection->websocketType = $originalType;
            }
        } else {
            // Polling连接：使用base64编码
            $base64Data = base64_encode($binaryData);
            $packet = 'b' . $base64Data; // 'b'前缀标识二进制数据包
            $this->send($packet);
        }
    }

    /**
     * 生成标准的会话ID（24字符十六进制）
     */
    public static function generateSid(): string
    {
        return bin2hex(random_bytes(12)); // 12字节 = 24字符十六进制
    }
    
    /**
     * 会话过期检查
     */
    public function isExpired(): bool
    {
        return (time() - $this->createdAt) > self::$sessionTtl;
    }
    
    /**
     * 内存管理：清理过期会话
     */
    private static function cleanupOldestSessions(int $batchSize = 100): void
    {
        $sessions = self::$sessions;
        uasort($sessions, fn($a, $b) => $a->createdAt <=> $b->createdAt);
        
        $cleaned = 0;
        foreach ($sessions as $sid => $session) {
            if ($session->isExpired()) {
                unset(self::$sessions[$sid]);
                unset(self::$cache[$sid]);
                $cleaned++;
                if ($cleaned >= $batchSize) break;
            }
        }
        
        if ($cleaned > 0) {
            echo "[cleanup] removed {$cleaned} expired sessions\n";
        }
    }

    /**
     * 清理缓存，防止内存泄漏
     */
    private static function cleanupCache(): void
    {
        if (count(self::$cache) > self::$cacheSize) {
            // 保留最近使用的会话
            $sessions = array_slice(self::$cache, -self::$cacheSize, self::$cacheSize, true);
            self::$cache = $sessions;
        }
    }
    
    /**
     * 定期清理过期会话和缓存
     */
    public static function cleanup(): void
    {
        // 清理过期会话
        self::cleanupOldestSessions(100);
        // 清理缓存
        self::cleanupCache();
    }

    /**
     * 向指定会话发送消息 - 智能处理单机和集群模式
     */
    public static function sendToSession(string $sid, array $packet): bool
    {
        // 优先尝试本地发送（单机模式或目标在本地）
        $session = self::get($sid);
        if ($session) {
            // Socket.IO v4协议兼容性修复：正确构建Engine.IO事件包
            $encodedPacket = json_encode($packet);
            // 类型42 = Socket.IO事件消息 (4=message, 2=event)
            $engineIOPacket = '42' . $encodedPacket;
            
            try {
                $session->send($engineIOPacket);
                echo "[send] direct sid={$sid} packet={$encodedPacket}\n";
                return true;
            } catch (Exception $e) {
                echo "[error] session send failed: " . $e->getMessage() . "\n";
                return false;
            }
        }
        
        // 未找到本地会话时，检查是否集群模式
        global $io;
        if (isset($io) && $io->isClusterEnabled()) {
            $adapter = $io->getAdapter();
            if ($adapter && method_exists($adapter, 'send') && $adapter->send($sid, $packet)) {
                echo "[cluster_send] forwarded sid={$sid}\n";
                return true;
            }
        }
        
        echo "[warning] session not found: {$sid}\n";
        return false;
    }

    /**
     * 获取会话ID
     */
    public function getSid(): string
    {
        return $this->sid;
    }

    /**
     * 检查会话是否可用
     */
    public static function isSessionAvailable(string $sid): bool
    {
        return isset(self::$sessions[$sid]);
    }

    /**
     * 清理所有会话
     */
    public static function clearAll(): void
    {
        self::$sessions = [];
        self::$cache = [];
        echo "[cleanup] cleared all sessions\n";
    }

    /**
     * 获取会话数量
     */
    public static function getSessionCount(): int
    {
        return count(self::$sessions);
    }

    /**
     * 检查会话是否活跃
     */
    public function isActive(): bool
    {
        return (time() - $this->lastPong) < self::$sessionTtl;
    }
    
    /**
     * 保存会话（兼容性方法）
     */
    public function save(): void
    {
        // 会话已经在构造函数中存储在 static::$sessions 中
        echo "[session] saved sid={$this->sid}\n";
    }
    
    /**
     * 关闭会话
     */
    public function close(): void
    {
        echo "[session] closing sid={$this->sid}\n";
        
        // 关闭 WebSocket 连接（如果存在）
        if ($this->connection) {
            try {
                $this->connection->close();
            } catch (Exception $e) {
                echo "[session] error closing connection: " . $e->getMessage() . "\n";
            }
        }
        
        // 离开所有房间
        global $io;
        if (isset($io) && method_exists($io, 'getRoomManager')) {
            $roomManager = $io->getRoomManager();
            if ($roomManager) {
                $roomManager->removeSession($this->sid);
            }
        }
        
        // 从会话池中移除
        self::remove($this->sid);
    }
}