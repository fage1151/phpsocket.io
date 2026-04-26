<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

/**
 * Socket.IO 会话管理类 - PHP 8.1+ 深度优化版本
 *
 * @package PhpSocketIO
 */
final class Session
{
    private const MAX_SESSIONS = 10000;
    private const SESSION_TTL = 86400;
    private const CACHE_SIZE = 1000;

    private static array $sessions = [];
    private static array $cache = [];
    private static array $cacheAccess = [];
    private static ?LoggerInterface $logger = null;

    public readonly string $sid;
    public string $transport = 'polling';
    public mixed $connection = null;
    public array $pollingQueue = [];
    public array $namespaces = [];
    public ?string $remoteIp = null;
    public int $lastPong;
    public int $lastPing = 0;
    public bool $isWs = false;
    public bool $upgraded = false;
    public bool $isPollingUpgrade = false; // 标记是否是从轮询升级来的
    public readonly int $createdAt;
    public int $messageCount = 0;
    public int $errorCount = 0;
    public array $ackCallbacks = [];
    public mixed $handshake = null;
    public array $data = [];
    public array $pendingBinaryAttachments = [];
    public mixed $pendingBinaryPlaceholder = null;
    public int $pendingBinaryCount = 0;
    public int $ackIdCounter = 0;
    
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public function __construct(string $sid)
    {
        $this->validateSid($sid);
        $this->ensureSessionLimit();
        $this->sid = $sid;
        $this->createdAt = time();
        $this->lastPong = $this->createdAt;
        self::$sessions[$sid] = $this;
        $this->manageCache();
    }

    private function validateSid(string $sid): void
    {
        if (strlen($sid) !== 24 || !ctype_xdigit($sid)) {
            throw new \InvalidArgumentException("Invalid session ID format: {$sid}");
        }
    }

    private function ensureSessionLimit(): void
    {
        $sessionCount = count(self::$sessions);
        if ($sessionCount >= self::MAX_SESSIONS) {
            $this->cleanupOldestSessions(100);
        }
    }

    private function manageCache(): void
    {
        if (count(self::$cache) > self::CACHE_SIZE) {
            $this->cleanupCache();
        }
    }

    public static function get(string $sid): ?self
    {
        if (isset(self::$cache[$sid])) {
            self::$cacheAccess[$sid] = time();
            return self::$cache[$sid];
        }

        if (isset(self::$sessions[$sid])) {
            self::$cache[$sid] = self::$sessions[$sid];
            self::$cacheAccess[$sid] = time();
            return self::$sessions[$sid];
        }

        return null;
    }

    public static function remove(string $sid): void
    {
        unset(self::$sessions[$sid], self::$cache[$sid], self::$cacheAccess[$sid]);
    }

    public static function all(): array
    {
        return self::$sessions;
    }

    public function enqueue(string $packet): void
    {
        $this->pollingQueue[] = $packet;
        
        // 通过单例模式唤醒等待的 polling 连接
        $server = SocketIOServer::getInstance();
        if ($server) {
            $server->wakePollingConnection($this->sid);
        }
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
        $shouldUseWebSocket = $this->connection && method_exists($this->connection, 'send');

        if ($shouldUseWebSocket) {
            $this->isWs = true;
            $this->transport = 'websocket';
            
            // 如果是从轮询升级来的，并且还没有升级完成，就加入队列
            if ($this->isPollingUpgrade && !$this->upgraded) {
                $this->enqueue($packet);
                return true;
            }
            
            // 否则直接通过 WebSocket 发送
            try {
                $result = HttpRequestHandler::sendWsFrame($this->connection, $packet, false, self::$logger);
                // 如果是从轮询升级来的，发送成功后标记为升级完成
                if ($this->isPollingUpgrade && !$this->upgraded) {
                    $this->upgraded = true;
                }
                return $result;
            } catch (\Exception $e) {
                self::$logger?->error('Session::send WebSocket 发送失败', [
                    'sid' => $this->sid,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }

        $this->enqueue($packet);                  
        return true;
    }

    public function sendBinary(string $binaryData): void
    {
        if ($this->isWs && $this->connection) {
            $originalType = $this->connection->websocketType ?? "\x81";
            $this->connection->websocketType = "\x82";

            try {
                $this->connection->send($binaryData);
            } finally {
                $this->connection->websocketType = $originalType;
            }
        } else {
            $this->send('b' . base64_encode($binaryData));
        }
    }

    public static function generateSid(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function isExpired(): bool
    {
        return (time() - $this->createdAt) > self::SESSION_TTL;
    }

    private function cleanupOldestSessions(int $batchSize): void
    {
        $cleaned = 0;
        $now = time();

        foreach (self::$sessions as $sid => $session) {
            if (($now - $session->createdAt) > self::SESSION_TTL) {
                unset(self::$sessions[$sid], self::$cache[$sid], self::$cacheAccess[$sid]);
                if (++$cleaned >= $batchSize) {
                    break;
                }
            }
        }
    }

    private function cleanupCache(): void
    {
        asort(self::$cacheAccess);
        $keysToRemove = array_slice(array_keys(self::$cacheAccess), 0, count(self::$cache) - self::CACHE_SIZE);
        foreach ($keysToRemove as $sid) {
            unset(self::$cache[$sid], self::$cacheAccess[$sid]);
        }
    }

    public static function cleanup(): void
    {
        $session = new self(self::generateSid()); // Temporary instance for access
        $session->cleanupOldestSessions(100);
        $session->cleanupCache();
    }

    public static function sendToSession(string $sid, array $packet): bool
    {
        $session = self::get($sid);
        if (!$session) {
            return false;
        }
        
        $engineIoPacket = '42' . json_encode($packet);
        
        try {
            return $session->send($engineIoPacket);
        } catch (\Exception $e) {
            error_log('Session::sendToAll failed: ' . $e->getMessage() . ' for sid: ' . $session->sid);
            return false;
        }
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public static function isSessionAvailable(string $sid): bool
    {
        return isset(self::$sessions[$sid]);
    }

    public static function clearAll(): void
    {
        self::$sessions = [];
        self::$cache = [];
        self::$cacheAccess = [];
    }

    public static function getSessionCount(): int
    {
        return count(self::$sessions);
    }

    public function isActive(): bool
    {
        return (time() - $this->lastPong) < self::SESSION_TTL;
    }
    
    /**
     * 设置客户端 IP 地址
     * @param string $ip 客户端 IP 地址
     */
    public function setRemoteIp(string $ip): void
    {
        $this->remoteIp = $ip;
    }
    
    /**
     * 设置客户端地址信息 (自动解析 IP)
     * @param string $address 客户端地址 (可能包含端口)
     */
    public function setRemoteAddress(string $address): void
    {
        // 提取 IP 地址（去掉端口）
        if (strpos($address, ':') !== false) {
            // 格式可能是 ip:port
            if (strpos($address, '[') === 0) {
                // IPv6 格式 [ip]:port
                $ipEnd = strpos($address, ']');
                if ($ipEnd !== false) {
                    $this->remoteIp = substr($address, 1, $ipEnd - 1);
                    return;
                }
            }
            
            // IPv4 或其他格式，取最后一个冒号前的部分
            $lastColonPos = strrpos($address, ':');
            if ($lastColonPos !== false) {
                $this->remoteIp = substr($address, 0, $lastColonPos);
                return;
            }
        }
        
        // 没有端口，直接使用
        $this->remoteIp = $address;
    }
    
    /**
     * 获取客户端 IP 地址
     */
    public function getRemoteIp(): ?string
    {
        if ($this->remoteIp !== null) {
            return $this->remoteIp;
        }
        
        // 如果 connection 可用，使用 Workerman 原生的 getRemoteIp()
        if ($this->connection && method_exists($this->connection, 'getRemoteIp')) {
            try {
                $this->remoteIp = $this->connection->getRemoteIp();
                return $this->remoteIp;
            } catch (\Exception) {
                return null;
            }
        }
        
        // 回退到 getRemoteAddress()
        if ($this->connection && method_exists($this->connection, 'getRemoteAddress')) {
            try {
                $address = $this->connection->getRemoteAddress();
                $this->setRemoteAddress($address);
                return $this->remoteIp;
            } catch (\Exception) {
                return null;
            }
        }
        
        return null;
    }

    public function save(): void
    {
    }

    public function close(): void
    {
        if ($this->connection && is_object($this->connection) && method_exists($this->connection, 'close')) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
                error_log('Session::close failed: ' . $e->getMessage() . ' for sid: ' . $this->sid);
            }
        }

        self::remove($this->sid);
    }
}
