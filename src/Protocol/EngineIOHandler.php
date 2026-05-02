<?php

declare(strict_types=1);

namespace PhpSocketIO\Protocol;

use Exception;
use PhpSocketIO\Session;
use PhpSocketIO\Event\EventHandler;
use PhpSocketIO\Room\RoomManager;
use Psr\Log\LoggerInterface;

/**
 * Engine.IO 协议处理器
 * 负责Engine.IO协议的握手、升级、心跳等底层通信逻辑
 * @package PhpSocketIO\Protocol
 */
class EngineIOHandler
{
    private int $pingInterval = 20000;
    private int $pingTimeout = 25000;
    private ?EventHandler $eventHandler = null;
    private ?RoomManager $roomManager = null;
    private mixed $onSocketIOMessage = null;
    private mixed $onBinaryMessage = null;
    private ?LoggerInterface $logger = null;

    /**
     * 设置日志记录器
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 构造函数
     */
    public function __construct(array $options = [])
    {
        $this->pingInterval = $options['pingInterval'] ?? 20000;
        $this->pingTimeout = $options['pingTimeout'] ?? 25000;
    }

    /**
     * 设置事件处理器依赖
     */
    public function setEventHandler(EventHandler $eventHandler): void
    {
        $this->eventHandler = $eventHandler;
    }

    /**
     * 设置房间管理器依赖
     */
    public function setRoomManager(RoomManager $roomManager): void
    {
        $this->roomManager = $roomManager;
    }

    /**
     * 设置Socket.IO消息处理回调
     */
    public function setSocketIOMessageHandler(callable $callback): void
    {
        $this->onSocketIOMessage = $callback;
    }

    /**
     * 设置二进制消息处理回调
     */
    public function setBinaryMessageHandler(callable $callback): void
    {
        $this->onBinaryMessage = $callback;
    }

    /**
     * 处理Engine.IO数据包
     */
    public function handlePacket(mixed $data, array $packet, mixed $connection, Session $session): bool
    {
        $this->logger?->debug('Engine.IO 收到数据包', [
            'sid' => $session->sid,
            'type' => $packet['type'],
            'transport' => $session->transport
        ]);

        switch ($packet['type']) {
            case 'PING':
                return $this->handleHeartbeat($connection, $session, $packet);
            case 'PONG':
                return $this->handlePong($session);
            case 'MESSAGE':
                return $this->handleMessage($packet, $connection, $session);
            case 'binary':
                return $this->handleBinary($packet, $connection, $session);
            case 'UPGRADE':
                $session->upgraded = true;
                $session->transport = 'websocket';
                $session->isWs = true;

                $this->logger?->info('Engine.IO 协议升级完成', [
                    'sid' => $session->sid,
                    'from' => 'polling',
                    'to' => 'websocket'
                ]);

                $messages = $session->flush();
                foreach ($messages as $msg) {
                    $session->send($msg);
                }

                if (is_object($connection) && method_exists($connection, 'send')) {
                    $connection->send('6');
                }
                return true;
            case 'NOOP':
                return true;
            default:
                $this->logger?->warning('Engine.IO 收到未知数据包类型', [
                    'sid' => $session->sid,
                    'type' => $packet['type']
                ]);
                return false;
        }
    }

    /**
     * 处理心跳请求 (ping)
     */
    private function handleHeartbeat(mixed $connection, Session $session, array $packetData = null): bool
    {
        if (isset($packetData['data']) && $packetData['data'] === 'probe') {
            $this->logger?->debug('Engine.IO 收到升级探测包', [
                'sid' => $session->sid
            ]);
            if (is_object($connection) && method_exists($connection, 'send')) {
                $connection->send('3probe');
            }
            $session->updateLastPong();
            return true;
        }

        $session->updateLastPong();
        return true;
    }

    /**
     * 处理心跳响应 (pong)
     */
    private function handlePong(Session $session): bool
    {
        $session->updateLastPong();
        return true;
    }

    /**
     * 处理文本消息
     */
    private function handleMessage(array $packet, mixed $connection, Session $session): bool
    {
        $message = $packet['data'];

        if ($this->onSocketIOMessage !== null) {
            ($this->onSocketIOMessage)($message, $connection, $session);
        }

        return true;
    }

    /**
     * 处理二进制数据
     */
    private function handleBinary(array $packet, mixed $connection, Session $session): bool
    {
        $binaryData = base64_decode($packet['data']);

        if ($this->onBinaryMessage !== null) {
            ($this->onBinaryMessage)($binaryData, $connection, $session);
        }

        return true;
    }

    /**
     * 发送握手响应
     */
    public function sendHandshake(mixed $connection, Session $session): void
    {
        $isWsConnection = is_object($connection) && isset($connection->isWs) && $connection->isWs;

        $handshake = [
            'sid' => $session->sid,
            'upgrades' => $isWsConnection ? [] : ['websocket'],
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout
        ];

        $packet = PacketParser::buildEngineIOPacket('OPEN', $handshake);
        if (is_object($connection) && method_exists($connection, 'send')) {
            $connection->send($packet);
        }

        $this->logger?->info('Engine.IO 握手完成', [
            'sid' => $session->sid,
            'transports' => $isWsConnection ? ['websocket'] : ['polling', 'websocket'],
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout
        ]);
    }

    /**
     * 发送Socket.IO消息
     */
    public function sendSocketIOMessage(mixed $data, Session $session): void
    {
        $socketIOPacket = is_array($data)
            ? PacketParser::buildSocketIOPacket('EVENT', $data)
            : $data;

        $engineIOPacket = PacketParser::buildEngineIOPacket('MESSAGE', $socketIOPacket);
        $session->send($engineIOPacket);
    }

    /**
     * 发送二进制数据
     */
    public function sendBinaryData(string $binaryData, Session $session): void
    {
        if ($session->isWs) {
            $session->sendBinary($binaryData);
        } else {
            $packet = 'b' . base64_encode($binaryData);
            $session->send($packet);
        }
    }

    /**
     * 处理会话心跳
     */
    public function processSessionHeartbeat(Session $session, int $interval, int $timeout): array
    {
        $intervalSec = $interval / 1000;
        $timeoutSec = $timeout / 1000;
        $now = time();

        if ($now - $session->lastPong > $timeoutSec + $intervalSec) {
            return ['status' => 'timeout', 'session' => $session];
        }

        if ($session->connection && $now - $session->lastPong > $intervalSec) {
            try {
                if (is_object($session->connection) && method_exists($session->connection, 'send')) {
                    $session->connection->send('2');
                }
                $session->lastPing = $now;
                return ['status' => 'ping-sent', 'session' => $session];
            } catch (Exception $e) {
                $this->logger?->error('Failed to send ping', [
                    'sid' => $session->sid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return ['status' => 'error', 'session' => $session, 'error' => $e->getMessage()];
            }
        }

        return ['status' => 'ok', 'session' => $session];
    }

    /**
     * 处理轮询请求
     */
    public function handlePolling(mixed $connection, Session $session): void
    {
        $messages = $session->flush();

        if (!empty($messages)) {
            $response = '';
            foreach ($messages as $msg) {
                $response .= strlen($msg) . ':' . $msg;
            }

            if (is_object($connection) && method_exists($connection, 'send')) {
                $connection->send($response);
            }
        } else {
            if (is_object($connection) && method_exists($connection, 'send')) {
                $connection->send('1:1');
            }
        }
    }

    /**
     * 处理POST请求（接收客户端消息）
     */
    public function handlePost(string $payload, Session $session): int
    {
        $messages = [];
        $offset = 0;
        $payloadLength = strlen($payload);

        while ($offset < $payloadLength) {
            $colonPos = strpos($payload, ':', $offset);
            if ($colonPos === false) {
                break;
            }

            $lengthStr = substr($payload, $offset, $colonPos - $offset);
            $length = intval($lengthStr);

            if ($length <= 0) {
                break;
            }

            $messageStart = $colonPos + 1;
            $message = substr($payload, $messageStart, $length);

            $messages[] = $message;
            $offset = $messageStart + $length;
        }

        foreach ($messages as $msg) {
            $packet = PacketParser::parseEngineIOPacket($msg);
            if ($packet) {
                $this->handlePacket($msg, $packet, null, $session);
            }
        }

        return count($messages);
    }

    /**
     * 获取心跳配置
     */
    public function getHeartbeatConfig(): array
    {
        return [$this->pingInterval, $this->pingTimeout];
    }

    /**
     * 启动高性能心跳机制
     */
    public function startHeartbeat(): void
    {
        $checkInterval = 5;
        $cleanupCounter = 0;

        \Workerman\Timer::add($checkInterval, function () use (&$cleanupCounter) {
            $sessions = Session::all();

            foreach ($sessions as $session) {
                $result = $this->processSessionHeartbeat($session, $this->pingInterval, $this->pingTimeout);

                if ($result['status'] === 'timeout') {
                    $this->cleanupSession($result['session']);
                }
            }

            if (++$cleanupCounter >= 6) {
                Session::cleanup();
                $cleanupCounter = 0;
            }
        });
    }

    /**
     * 清理超时会话
     */
    private function cleanupSession(Session $session): void
    {
        if ($this->eventHandler) {
            foreach ($session->namespaces as $namespace => $auth) {
                $this->eventHandler->triggerDisconnect(
                    [
                        'id' => $session->sid,
                        'session' => $session,
                        'namespace' => $namespace,
                        'socket' => null,
                    ],
                    'ping timeout'
                );
            }
        }

        if ($session->connection) {
            if (is_object($session->connection) && method_exists($session->connection, 'close')) {
                try {
                    $session->connection->close();
                } catch (Exception $e) {
                    $this->logger?->error('Failed to close connection', [
                        'sid' => $session->sid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        if ($this->roomManager) {
            $this->roomManager->leaveAllRooms($session->sid);
        }

        Session::remove($session->sid);
    }

    /**
     * 处理Workerman解析后的WebSocket数据
     */
    public function processWebSocketData(Session $session, mixed $data): void
    {
        $this->logger?->debug('WebSocket 收到数据', [
            'sid' => $session->sid,
            'data' => is_string($data) ? substr($data, 0, 100) : gettype($data),
            'upgraded' => $session->upgraded,
            'isPollingUpgrade' => $session->isPollingUpgrade
        ]);

        $packet = PacketParser::parseEngineIOPacket($data);
        if (!$packet) {
            $this->logger?->warning('无法解析 Engine.IO 数据包', ['sid' => $session->sid]);
            return;
        }

        $this->logger?->debug('解析出 Engine.IO 数据包', [
            'sid' => $session->sid,
            'type' => $packet['type']
        ]);

        if (!$session->connection) {
            $this->logger?->warning('Session 没有 connection', ['sid' => $session->sid]);
            return;
        }

        if ($session->isPollingUpgrade && !$session->upgraded) {
            $allowedTypesBeforeUpgrade = ['PING', 'PONG', 'UPGRADE', 'NOOP'];
            if (!in_array($packet['type'], $allowedTypesBeforeUpgrade)) {
                $this->logger?->warning('从轮询升级过程中，不允许的数据包类型', [
                    'sid' => $session->sid,
                    'type' => $packet['type']
                ]);
                return;
            }
        }

        $this->handlePacket($data, $packet, $session->connection, $session);
    }

    /**
     * 获取事件处理器
     */
    public function getEventHandler(): ?EventHandler
    {
        return $this->eventHandler;
    }

    /**
     * 获取房间管理器
     */
    public function getRoomManager(): ?RoomManager
    {
        return $this->roomManager;
    }

    /**
     * 获取心跳间隔时间
     */
    public function getPingInterval(): int
    {
        return $this->pingInterval;
    }

    /**
     * 获取心跳超时时间
     */
    public function getPingTimeout(): int
    {
        return $this->pingTimeout;
    }
}
