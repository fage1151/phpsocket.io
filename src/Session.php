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
        $this->handshake = $this->createDefaultHandshake();
        self::$sessions[$sid] = $this;
        $this->manageCache();
    }

    private function createDefaultHandshake(): array
    {
        return [
            'headers' => [],
            'time' => date('c'),
            'issued' => time() * 1000,
            'address' => null,
            'xdomain' => false,
            'secure' => false,
            'url' => null,
            'query' => [],
            'auth' => null,
        ];
    }

    public function setHandshake(array $handshakeData): void
    {
        $this->handshake = array_merge($this->createDefaultHandshake(), $handshakeData);
        $this->handshake['time'] = date('c', $this->createdAt);
        $this->handshake['issued'] = $this->createdAt * 1000;
    }

    public function updateHandshake(array $data): void
    {
        if (is_array($this->handshake)) {
            $this->handshake = array_merge($this->handshake, $data);
        }
    }

    private function validateSid(string $sid): void
    {
        if (strlen($sid) !== 24 || !ctype_xdigit($sid)) {
            throw new \InvalidArgumentException("Invalid session ID format: {$sid}");
        }
    }

    public static function validateSidFormat(string $sid): bool
    {
        return strlen($sid) === 24 && ctype_xdigit($sid);
    }

    private function ensureSessionLimit(): void
    {
        $sessionCount = count(self::$sessions);
        if ($sessionCount >= self::MAX_SESSIONS) {
            self::cleanupOldestSessionsInternal(100);
        }
    }

    private function manageCache(): void
    {
        if (count(self::$cache) > self::CACHE_SIZE) {
            self::cleanupCacheInternal();
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



    public static function cleanup(): void
    {
        self::cleanupOldestSessionsInternal(100);
        self::cleanupCacheInternal();
    }

    private static function cleanupOldestSessionsInternal(int $batchSize): void
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

    private static function cleanupCacheInternal(): void
    {
        asort(self::$cacheAccess);
        $keysToRemove = array_slice(array_keys(self::$cacheAccess), 0, count(self::$cache) - self::CACHE_SIZE);
        foreach ($keysToRemove as $sid) {
            unset(self::$cache[$sid], self::$cacheAccess[$sid]);
        }
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
     * 获取客户端 IP 地址
     */
    public function getRemoteIp(): ?string
    {
        return $this->remoteIp;
    }

    public function save(): void
    {
        self::$sessions[$this->sid] = $this;
        self::$cache[$this->sid] = $this;
        self::$cacheAccess[$this->sid] = time();
    }

    public function toArray(): array
    {
        return [
            'sid' => $this->sid,
            'transport' => $this->transport,
            'namespaces' => $this->namespaces,
            'remoteIp' => $this->remoteIp,
            'createdAt' => $this->createdAt,
            'lastPong' => $this->lastPong,
            'isWs' => $this->isWs,
            'upgraded' => $this->upgraded,
            'messageCount' => $this->messageCount,
            'errorCount' => $this->errorCount,
        ];
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
