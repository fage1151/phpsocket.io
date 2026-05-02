<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

use PhpSocketIO\Transport\HttpRequestHandler;
use PhpSocketIO\SocketIOServer;

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
    private const ACTIVE_TIMEOUT = 60;
    private const RECOVERY_MAX_DURATION = 120;
    private const RECOVERY_MAX_PACKETS = 1000;

    private static array $sessions = [];
    private static array $cache = [];
    private static array $cacheAccess = [];
    private static ?LoggerInterface $logger = null;
    private static array $recoveryStore = [];

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
    public ?string $pid = null;
    public int $offsetCounter = 0;
    public array $sentPackets = [];

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public function __construct(string $sid)
    {
        $this->validateSid($sid);
        $this->ensureSessionLimit();
        $this->sid = $sid;
        $this->pid = $this->generatePid();
        $this->createdAt = time();
        $this->lastPong = $this->createdAt;
        $this->handshake = $this->createDefaultHandshake();
        self::$sessions[$sid] = $this;
        $this->manageCache();
    }

    private function generatePid(): string
    {
        return bin2hex(random_bytes(12));
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
            if ($this->isPollingUpgrade && !$this->upgraded) {
                $this->enqueue($packet);
                return true;
            }

            try {
                $result = HttpRequestHandler::sendWsFrame($this->connection, $packet, false, self::$logger);
                if ($this->isPollingUpgrade && !$this->upgraded) {
                    $this->upgraded = true;
                    $this->transport = 'websocket';
                    $this->isWs = true;
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

    public static function sendToSession(string $sid, string $packet): bool
    {
        $session = self::get($sid);
        if (!$session) {
            return false;
        }

        try {
            return $session->send($packet);
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
        return (time() - $this->lastPong) < self::ACTIVE_TIMEOUT;
    }

    /**
     * 设置客户端 IP 地址
     * @param string $ip 客户端 IP 地址
     */
    public function setRemoteIp(string $ip): void
    {
        $this->remoteIp = $ip;
        
        if (is_array($this->handshake)) {
            $this->handshake['address'] = $ip;
        }
    }

    /**
     * 获取客户端 IP 地址
     * 优先从 handshake['address'] 获取，如果没有则返回 remoteIp 属性
     */
    public function getRemoteIp(): ?string
    {
        if (is_array($this->handshake) && isset($this->handshake['address'])) {
            return $this->handshake['address'];
        }
        
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

    public function generateOffset(): string
    {
        $this->offsetCounter++;
        return base64_encode(pack('JN', $this->offsetCounter, mt_rand()));
    }

    public function recordSentPacket(string $offset, string $packet): void
    {
        $this->sentPackets[] = [
            'offset' => $offset,
            'packet' => $packet,
            'timestamp' => time(),
        ];

        if (count($this->sentPackets) > self::RECOVERY_MAX_PACKETS) {
            array_shift($this->sentPackets);
        }
    }

    public static function storeForRecovery(string $sid, array $rooms, array $data, ?string $pid = null): void
    {
        if (!$pid) {
            return;
        }

        self::$recoveryStore[$pid] = [
            'sid' => $sid,
            'rooms' => $rooms,
            'data' => $data,
            'timestamp' => time(),
        ];

        self::cleanupRecoveryStore();
    }

    public static function tryRecover(?string $pid, ?string $offset): ?array
    {
        if (!$pid || !isset(self::$recoveryStore[$pid])) {
            return null;
        }

        $stored = self::$recoveryStore[$pid];
        $elapsed = time() - $stored['timestamp'];

        if ($elapsed > self::RECOVERY_MAX_DURATION) {
            unset(self::$recoveryStore[$pid]);
            return null;
        }

        $missedPackets = [];
        $session = self::get($stored['sid']);
        if ($session && $offset) {
            $foundOffset = false;
            foreach ($session->sentPackets as $sentPacket) {
                if ($foundOffset) {
                    $missedPackets[] = $sentPacket['packet'];
                } elseif ($sentPacket['offset'] === $offset) {
                    $foundOffset = true;
                }
            }
        }

        unset(self::$recoveryStore[$pid]);

        return [
            'sid' => $stored['sid'],
            'rooms' => $stored['rooms'],
            'data' => $stored['data'],
            'missedPackets' => $missedPackets,
        ];
    }

    private static function cleanupRecoveryStore(): void
    {
        $now = time();
        foreach (self::$recoveryStore as $pid => $data) {
            if (($now - $data['timestamp']) > self::RECOVERY_MAX_DURATION) {
                unset(self::$recoveryStore[$pid]);
            }
        }
    }
}
