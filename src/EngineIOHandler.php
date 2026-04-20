<?php

namespace PhpSocketIO;

use Exception;

/**
 * Engine.IO 协议处理器
 * 负责Engine.IO协议的握手、升级、心跳等底层通信逻辑
 * @package SocketIO
 */
class EngineIOHandler
{
    private int $pingInterval = 20000; // 心跳间隔(20秒)
    private int $pingTimeout  = 25000; // 心跳超时(25秒)
    private $eventHandler = null;  // 事件处理器
    private $roomManager = null;   // 房间管理器
    private $onSocketIOMessage = null; // Socket.IO消息处理回调
    private $onBinaryMessage = null; // 二进制消息处理回调
    private $onEngineIOPacket = null; // Engine.IO数据包处理回调

    /**
     * 构造函数
     */
    public function __construct(array $options = [])
    {
        $this->pingInterval = $options['pingInterval'] ?? 20000;
        $this->pingTimeout  = $options['pingTimeout']  ?? 25000;
    }

    /**
     * 设置事件处理器依赖
     */
    public function setEventHandler($eventHandler): void
    {
        $this->eventHandler = $eventHandler;
    }

    /**
     * 设置房间管理器依赖
     */
    public function setRoomManager($roomManager): void
    {
        $this->roomManager = $roomManager;
    }

    /**
     * 设置Socket.IO消息处理回调
     */
    public function setSocketIOMessageHandler($callback): void
    {
        $this->onSocketIOMessage = $callback;
    }

    /**
     * 设置二进制消息处理回调
     */
    public function setBinaryMessageHandler($callback): void
    {
        $this->onBinaryMessage = $callback;
    }

    /**
     * 设置Engine.IO数据包处理回调
     */
    public function setEngineIOPacketHandler($callback): void
    {
        $this->onEngineIOPacket = $callback;
    }

    /**
     * 处理Engine.IO数据包
     */
    public function handlePacket($data, $packet, $connection, Session $session)
    {
        switch ($packet['type']) {
            case 'ping':
                return $this->handleHeartbeat($connection, $session, $packet);
            case 'pong':
                return $this->handlePong($session);
            case 'message':
                return $this->handleMessage($packet, $connection, $session);
            case 'binary':
                return $this->handleBinary($packet, $connection, $session);
            case 'upgrade':
                // 客户端发送"5"表示升级完成确认
                $session->upgraded = true;
                echo "[engineio] received upgrade confirmation from sid={$session->sid}\n";
                
                // 先发送队列里的所有消息
                $messages = $session->flush();
                foreach ($messages as $msg) {
                    $session->send($msg);
                }
                echo "[engineio] sent " . count($messages) . " queued messages after upgrade\n";
                
                // 协议要求：升级完成后发送一个 noop (6) 包
                $connection->send('6');
                return true;
            case 'noop':
                return true; // 忽略空操作
            default:
                return false;
        }
    }

    /**
     * 处理心跳请求 (ping)
     */
    private function handleHeartbeat($connection, Session $session, $packetData = null)
    {
        if (isset($packetData['data']) && $packetData['data'] === 'probe') {
            // 处理WebSocket升级探测包
            echo "[engineio] received websocket upgrade probe from sid={$session->sid}, responding with 3probe\n";
            $connection->send('3probe');
            $session->updateLastPong();
            return true;
        }
        
        // 普通ping包，发送pong响应
        $connection->send('3');
        $session->updateLastPong();
        
        return true;
    }

    /**
     * 处理心跳响应 (pong)
     */
    private function handlePong(Session $session)
    {
        $session->updateLastPong();
        return true;
    }

    /**
     * 处理文本消息
     */
    private function handleMessage($packet, $connection, Session $session)
    {
        $message = $packet['data'];
        
        echo "[engineio] raw message for sid={$session->sid}: " . (is_string($message) ? $message : json_encode($message)) . "\n";
        
        // 直接将消息传递给 Socket.IO 处理器
        if (isset($this->onSocketIOMessage)) {
            call_user_func($this->onSocketIOMessage, $message, $connection, $session);
        }
        
        return true;
    }

    /**
     * 处理二进制数据
     */
    private function handleBinary($packet, $connection, Session $session)
    {
        $binaryData = base64_decode($packet['data']);
        $dataSize = strlen($binaryData);
        
        echo "[engineio] binary data for sid={$session->sid}, size={$dataSize} bytes\n";
        
        // 调用外部处理函数
        if (isset($this->onBinaryMessage)) {
            call_user_func($this->onBinaryMessage, $binaryData, $connection, $session);
        }
        
        return true;
    }

    /**
     * 发送握手响应
     */
    public function sendHandshake($connection, Session $session)
    {
        $handshake = [
            'sid' => $session->sid,
            'upgrades' => $connection->isWs ? [] : ['websocket'],
            'pingInterval' => $this->pingInterval,
            'pingTimeout' => $this->pingTimeout
        ];
    
        $packet = PacketParser::buildEngineIOPacket('open', $handshake);
        $connection->send($packet);
        
        echo "[engineio] handshake sent for sid={$session->sid}\n";
    }

    /**
     * 发送Socket.IO消息
     */
    public function sendSocketIOMessage($data, Session $session)
    {
        // 构建Engine.IO消息包
        $socketIOPacket = is_array($data) 
            ? PacketParser::buildSocketIOPacket('EVENT', $data) 
            : $data;
        
        $engineIOPacket = PacketParser::buildEngineIOPacket('message', $socketIOPacket);
        $session->send($engineIOPacket);
        
        echo "[engineio] socketio message sent to sid={$session->sid}\n";
    }

    /**
     * 发送二进制数据
     */
    public function sendBinaryData($binaryData, Session $session)
    {
        if ($session->isWs) {
            // WebSocket发送二进制数据
            $session->sendBinary($binaryData);
        } else {
            // Polling发送base64编码的二进制数据
            $packet = 'b' . base64_encode($binaryData);
            $session->send($packet);
        }
        
        $dataSize = strlen($binaryData);
        echo "[engineio] binary data sent to sid={$session->sid}, size={$dataSize} bytes\n";
    }

    /**
     * 处理会话心跳
     */
    public function processSessionHeartbeat(Session $session, $interval, $timeout)
    {
        // 转换为秒进行比较（因为lastPong是秒级时间戳）
        $intervalSec = $interval / 1000;
        $timeoutSec = $timeout / 1000;
        $now = time();
        
        // 检查会话是否过期
        if ($now - $session->lastPong > $timeoutSec + $intervalSec) {
            return ['status' => 'timeout', 'session' => $session];
        }
        
        // 检查是否应该发送ping（确保有连接并且距上次pong超过间隔时间）
        if ($session->connection && $now - $session->lastPong > $intervalSec) {
            try {
                // Engine.IO协议：ping包就是字符串'2'
                $session->connection->send('2'); // 发送ping
                // 记录发送ping的时间，避免连续发送
                $session->lastPing = $now;

                return ['status' => 'ping-sent', 'session' => $session];
            } catch (Exception $e) {
                return ['status' => 'error', 'session' => $session, 'error' => $e->getMessage()];
            }
        }
        
        return ['status' => 'ok', 'session' => $session];
    }

    /**
     * 处理轮询请求
     */
    public function handlePolling($connection, Session $session)
    {
        // 检查是否有待发送的消息
        $messages = $session->flush();
        
        if (!empty($messages)) {
            // 将多个消息合并为一个HTTP响应
            $response = array_reduce($messages, function($carry, $msg) {
                return $carry . strlen($msg) . ':' . $msg;
            }, '');
            
            $connection->send($response);
            echo "[engineio] polling response sent to sid={$session->sid}, messages=" . count($messages) . "\n";
        } else {
            // 如果没有消息，发送空响应
            $connection->send('1:1'); // 单个noop消息
            echo "[engineio] polling response (empty) sent to sid={$session->sid}\n";
        }
    }

    /**
     * 处理POST请求（接收客户端消息）
     */
    public function handlePost($payload, Session $session)
    {
        // 解析多部分消息
        $messages = [];
        $offset = 0;
        $payloadLength = strlen($payload);
        
        while ($offset < $payloadLength) {
            // 查找冒号分隔符
            $colonPos = strpos($payload, ':', $offset);
            if ($colonPos === false) break;
            
            // 读取消息长度
            $lengthStr = substr($payload, $offset, $colonPos - $offset);
            $length = intval($lengthStr);
            
            if ($length <= 0) break;
            
            // 读取消息内容
            $messageStart = $colonPos + 1;
            $message = substr($payload, $messageStart, $length);
            
            $messages[] = $message;
            $offset = $messageStart + $length;
        }
        
        // 处理每个消息
        foreach ($messages as $msg) {
            echo "[engineio] received POST message for sid={$session->sid}: {$msg}\n";
            
            // 解析Engine.IO数据包
            $packet = PacketParser::parseEngineIOPacket($msg);
            if ($packet) {
                if (isset($this->onEngineIOPacket)) {
                    call_user_func($this->onEngineIOPacket, $msg, $packet, null, $session);
                }
            }
        }
        
        return count($messages);
    }

    /**
     * 设置外部回调函数
     */
    public function setCallback($type, $callback)
    {
        $this->{$type} = $callback;
    }

    /**
     * 获取心跳配置
     */
    public function getHeartbeatConfig()
    {
        return [$this->pingInterval, $this->pingTimeout];
    }

    /**
     * 启动高性能心跳机制 - 批量处理所有会话的心跳
     * 使用Workerman的Timer定时执行，避免阻塞
     */
    public function startHeartbeat()
    {
        // 每5秒检查一次会话状态，减少不必要的检查
        $checkInterval = 5;
        $cleanupCounter = 0;
        
        // 使用Workerman的Timer定时执行心跳检查
        \Workerman\Timer::add($checkInterval, function() use (&$cleanupCounter) {
            $sessions = Session::all();
            
            foreach ($sessions as $session) {
                // 批量处理每个会话的心跳
                $result = $this->processSessionHeartbeat($session, $this->pingInterval, $this->pingTimeout);
                
                // 如果会话超时，进行清理
                if ($result['status'] === 'timeout') {
                    $this->cleanupSession($result['session']);
                }
            }
            
            // 每30秒（6个周期）执行一次会话和缓存清理
            if (++$cleanupCounter >= 6) {
                Session::cleanup();
                $cleanupCounter = 0;
            }
        });
    }

    /**
     * 清理超时会话，关闭连接并释放资源
     */
    private function cleanupSession($session)
    {
        if ($session->connection) {
            // 关闭WebSocket连接
            $session->connection->close();
            echo "[cleanup] closed connection for session: {$session->sid}\n";
        }
        
        // 从会话池中移除
        Session::remove($session->sid);
        echo "[cleanup] removed session: {$session->sid}\n";
    }
    
    /**
     * 处理Workerman解析后的WebSocket消息
     * Workerman已经自动解析了WebSocket帧，这里直接处理解析后的消息
     */
    public function processWebSocketData(Session $session, $data)
    {
        // Engine.IO协议处理：直接处理解析后的消息
        $packet = PacketParser::parseEngineIOPacket($data);
        if (!$packet) {
            // 特殊处理：尝试检查是否是特殊的"451"错误代码等格式
            if (strlen($data) > 0 && !empty(trim($data))) {
                echo "[engineio] Failed to parse Engine.IO packet: raw=\"" . substr($data, 0, 100) . "\" (len=" . strlen($data) . ")\n";
            }
            return;
        }
        
        // 获取会话对应的连接
        if (!$session->connection) {
            echo "[engineio] No connection available for WebSocket session: {$session->sid}\n";
            return;
        }
        
        // 在升级完成前，只允许处理升级相关的包
        // 允许的包类型：ping(2), pong(3), upgrade(5), noop(6)
        $allowedTypesBeforeUpgrade = ['ping', 'pong', 'upgrade', 'noop'];
        if (!$session->upgraded && !in_array($packet['type'], $allowedTypesBeforeUpgrade)) {
            echo "[engineio] Waiting for upgrade confirmation (sid={$session->sid}), ignoring packet: type={$packet['type']}\n";
            return;
        }
        
        // 处理Engine.IO数据包
        $this->handlePacket('', $packet, $session->connection, $session);
    }

    /**
     * 获取事件处理器
     */
    public function getEventHandler()
    {
        return $this->eventHandler;
    }

    /**
     * 获取房间管理器
     */
    public function getRoomManager()
    {
        return $this->roomManager;
    }

    /**
     * 获取心跳间隔时间
     */
    public function getPingInterval()
    {
        return $this->pingInterval;
    }

    /**
     * 获取心跳超时时间
     */
    public function getPingTimeout()
    {
        return $this->pingTimeout;
    }

    /**
     * 处理二进制数据
     */
    public function processBinaryData(Session $session, $placeholderPacket, $binaryData, $placeholderNum)
    {
        // 存储二进制附件
        $session->pendingBinaryAttachments[$placeholderNum] = $binaryData;
        
        // 处理完整的二进制包
        $this->processBinaryPacket($session, $placeholderPacket);
    }
    
    /**
     * 处理所有二进制附件合并后一起处理
     */
    public function processAllBinaryAttachments(Session $session, $placeholderPacket, $binaryAttachments)
    {
        // 存储所有二进制附件
        foreach ($binaryAttachments as $index => $binaryData) {
            $session->pendingBinaryAttachments[$index] = $binaryData;
        }
        
        // 处理完整的二进制包
        $this->processBinaryPacket($session, $placeholderPacket);
    }
    
    /**
     * 处理完整的二进制包
     */
    private function processBinaryPacket(Session $session, $placeholderPacket)
    {
        // 解析占位符包
        $engineIoPacket = PacketParser::parseEngineIOPacket($placeholderPacket);
        if (!$engineIoPacket || $engineIoPacket['type'] !== 'message') {
            return;
        }
        
        // 解析Socket.IO包
        $socketIoData = $engineIoPacket['data'];
        $socketIoPacket = PacketParser::parseSocketIOPacket($socketIoData);
        if (!$socketIoPacket) {
            return;
        }
        
        // 替换二进制占位符
        if (!empty($session->pendingBinaryAttachments)) {
            $socketIoPacket = PacketParser::replaceBinaryPlaceholders($socketIoPacket, $session->pendingBinaryAttachments);
        }
        
        // 处理完整的包
        $socket = [
            'id' => $session->sid,
            'session' => $session,
            'namespace' => isset($socketIoPacket['namespace']) ? $socketIoPacket['namespace'] : '/'
        ];
        
        if ($this->eventHandler) {
            $this->eventHandler->handlePacket($socketIoPacket, $socket);
        }
        
        // 清理已处理的二进制附件
        $session->pendingBinaryAttachments = [];
    }
}