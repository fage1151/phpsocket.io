<?php

namespace PhpSocketIO;

use Workerman\Connection\TcpConnection;

/**
 * Socket类 - 封装Socket.IO客户端连接接口
 * 提供事件收发、房间管理、连接控制等功能
 * @package PhpSocketIO
 */
class Socket
{
    public $sid;        // Session ID
    public $id;         // 兼容性ID
    public $namespace;  // 命名空间
    public $server;     // SocketIO服务器实例
    public $connection; // 连接对象
    public $session;    // 会话对象
    public $rooms = []; // 已加入的房间列表
    public $auth;       // 认证信息
    public $handshake;  // 握手信息
    public $headers;    // HTTP头信息（如果有）
    public $data = [];  // 任意数据对象 (v4.0.0+)

    public $broadcast = null;

    /**
     * 超时时间（毫秒）
     */
    private $timeout = null;

    /**
     * 排除的房间列表
     */
    private $exceptRooms = [];

    /**
     * 构造函数
     */
    public function __construct(?string $sid = null, string $namespace = '/', ?SocketIOServer $server = null, ?TcpConnection $connection = null)
    {
        $this->sid = $sid;
        $this->id = $sid; // 兼容性别名
        $this->namespace = $namespace;
        $this->server = $server;
        $this->connection = $connection;
        $this->session = Session::get($sid);
        
        // 从session中获取握手信息和data
        if ($this->session) {
            $this->handshake = $this->session->handshake;
            $this->data = &$this->session->data; // 引用，保持同步
        }
        
        // 初始化broadcast属性
        $this->broadcast = new SocketBroadcaster($server, $this);
    }

    /**
     * 发送事件到客户端 (Socket.IO v4标准接口)
     */
    public function emit(string $event, mixed ...$args): self
    {
        if (empty($event)) {
            throw new \InvalidArgumentException("事件名称不能为空");
        }
        
        // 验证事件名称格式，只允许字母、数字、下划线和点
        if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $event)) {
            throw new \InvalidArgumentException("事件名称格式无效");
        }
        
        // 检查是否包含二进制数据并发送
        return $this->hasBinaryData($args)
            ? $this->emitBinary($event, ...$args)
            : $this->sendStandardEvent($event, ...$args);
    }
    
    /**
     * 发送标准事件
     */
    private function sendStandardEvent(string $event, mixed ...$args): self
    {
        $packetData = $this->buildEventPacket($event, $args);
        $this->send($packetData, $event);
        return $this;
    }
    
    /**
     * 构建事件消息包
     */
    public function buildEventPacket(string $event, array $args): string
    {
        $eventData = array_merge([$event], $args);
        $socketIOPacket = json_encode($eventData);
        return $this->buildPacket('42', $socketIOPacket);
    }

    /**
     * 发送二进制事件 (Socket.IO v4标准)
     */
    public function emitBinary(string $event, mixed ...$args): self
    {
        if (empty($event)) {
            throw new \InvalidArgumentException("事件名称不能为空");
        }
        // 收集二进制附件并替换为占位符
        [$binaryAttachments, $processedArgs] = $this->processBinaryData($args);
        // 构建二进制事件包前缀（根据附件数量）
        $binaryCount = count($binaryAttachments);
        $prefix = '45' . $binaryCount . '-';
        // 构建并发送二进制事件包
        $eventData = array_merge([$event], $processedArgs);
        $socketIOPacket = json_encode($eventData);
        $packetData = $this->buildPacket($prefix, $socketIOPacket);
        
        if ($this->session) {
            // 发送文本包（包含占位符）
            $this->session->send($packetData);
            
            // 发送二进制附件
            $this->sendBinaryData($binaryAttachments);
        }
        
        return $this;
    }
    
    /**
     * 检查参数是否包含二进制数据
     */
    private function hasBinaryData(array $args): bool
    {
        foreach ($args as $arg) {
            if ($this->isBinaryData($arg)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 处理二进制数据，创建占位符
     */
    private function processBinaryData(array $args): array
    {
        $binaryAttachments = [];
        $processedArgs = [];
        
        foreach ($args as $arg) {
            if ($this->isBinaryData($arg)) {
                // 二进制数据，创建占位符
                $placeholder = ['_placeholder' => true, 'num' => count($binaryAttachments)];
                $binaryAttachments[] = $arg;
                $processedArgs[] = $placeholder;
            } else {
                $processedArgs[] = $arg;
            }
        }
        
        return [$binaryAttachments, $processedArgs];
    }
    
    /**
     * 构建Engine.IO数据包
     */
    private function buildPacket(string $prefix, string $socketIOPacket): string
    {
        $namespacePart = $this->namespace !== '/' ? $this->namespace . ',' : '';
        
        // 普通事件格式
        return $prefix . $namespacePart . $socketIOPacket;
    }
    
    /**
     * 发送消息
     */
    private function send(string $packetData, string $event): void
    {
        $this->session && $this->session->send($packetData);
    }
    
    /**
     * 发送二进制数据
     */
    private function sendBinaryData(array $binaryAttachments): void
    {
        foreach ($binaryAttachments as $binaryData) {
            $this->session->sendBinary($binaryData);
        }
    }
    
    /**
     * 发送带ACK的事件
     */
    private function emitAckEvent(string $event, int $ackId, mixed ...$args): self
    {
        // 构建事件数据（包含ACK ID）
        $eventData = array_merge([$event], $args, [$ackId]);
        $socketIOPacket = json_encode($eventData);
        $packetData = $this->buildPacket('42', $socketIOPacket);
        
        if ($this->session) {
            // 发送带ACK的事件包
            $this->session->send($packetData);
        }
        
        return $this;
    }

    /**
     * 检查数据是否为二进制数据
     */
    private function isBinaryData(mixed $data): bool
    {
        if (!is_string($data)) {
            return false;
        }
        
        // 快速检查：如果包含 null 字节，视为二进制
        if (str_contains($data, "\x00")) {
            return true;
        }
        if (!ctype_print($data)) {
            return true;
        }
        // 检查控制字符比例
        $controlCharCount = 0;
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i++) {
            $char = ord($data[$i]);
            // 控制字符（除了制表符、换行、回车）
            if ($char < 32 && !in_array($char, [9, 10, 13])) {
                $controlCharCount++;
            }
        }
        
        // 如果控制字符比例超过 10%，视为二进制
        if ($controlCharCount > $length * 0.1) {
            return true;
        }
        
        // 检查是否为有效的 UTF-8 文本
        return @json_encode($data) === false;
    }

    /**
     * Socket实例级别的事件处理器缓存
     */
    private $eventHandlers = [];

    /**
     * 注册事件监听器 (Socket.IO v4标准接口)
     */
    public function on(string $event, callable $callback): self
    {
        if (!$this->server) {
            throw new \RuntimeException("Socket实例未关联到服务器");
        }
        
        // 缓存到Socket实例级别的事件处理器
        $this->eventHandlers[$event] = $callback;
        
        // 同时注册到服务器的事件处理器（兼容全局查找）
        $this->server->on($event, $callback, $this->namespace);
        
        return $this;
    }
    
    /**
     * 获取Socket实例级别的事件处理器
     */
    public function getEventHandler($event)
    {
        return $this->eventHandlers[$event] ?? null;
    }
    
    /**
     * 检查Socket实例是否存在特定事件处理器
     */
    public function hasEventHandler(string $event): bool
    {
        return isset($this->eventHandlers[$event]);
    }
    
    /**
     * 获取所有事件处理器（用于实例复用）
     */
    public function getAllEventHandlers(): array
    {
        return $this->eventHandlers;
    }
    
    /**
     * 设置事件处理器（用于实例复用）
     */
    public function setEventHandlers(array $handlers): void
    {
        $this->eventHandlers = $handlers;
    }

    /**
     * 加入房间 (Socket.IO v4群发功能)
     */
    public function join(string $room): self
    {
        if (empty($room)) {
            throw new \InvalidArgumentException("房间名称不能为空");
        }
        
        // 使用房间管理器加入房间
        if ($this->session && $this->server) {
            $this->server->getRoomManager()->join($room, $this->session);
            $this->rooms[$room] = true;
        }
        
        return $this; // 支持链式调用
    }

    /**
     * 离开房间
     */
    public function leave(string $room): self
    {
        if ($this->session && $this->server) {
            $this->server->getRoomManager()->leave($room, $this->session);
            unset($this->rooms[$room]);
        }
        
        return $this;
    }

    /**
     * 指定房间进行广播 (链式调用)
     * 例如: socket.to('room1').emit('message', 'Hello')
     */
    public function to($room)
    {
        // 创建一个广播器实例，专门处理房间广播
        $broadcaster = new SocketBroadcaster($this->server, $this);
        return $broadcaster->to($room);
    }

    /**
     * 断开连接
     */
    public function disconnect($close = false)
    {
        echo "[Socket] disconnect - sid={$this->sid}\n";
        
        // 触发断开事件，带reason参数
        $reason = $close ? 'server namespace disconnect' : 'client namespace disconnect';
        if ($this->server) {
            $this->server->getEventHandler()->triggerEvent($this->session, $this->namespace, 'disconnect', [$reason]);
        }
        
        // 清理会话
        if ($close && $this->session) {
            $this->session->close();
        }
        
        return $this;
    }

    /**
     * 设置超时时间 (v4.4.0+)
     * 用于带ACK的事件发送
     */
    public function timeout($value)
    {
        $this->timeout = $value;
        return $this;
    }

    /**
     * 排除特定房间的广播修饰符 (v4.0.0+)
     */
    public function except($rooms)
    {
        if (!is_array($rooms)) {
            $rooms = [$rooms];
        }
        $this->exceptRooms = array_merge($this->exceptRooms, $rooms);
        return $this;
    }

    /**
     * 带ACK的发送 (Promise风格, v4.6.0+)
     * 注意：PHP不支持Promise，这里使用回调函数方式
     */
    public function emitWithAck($event, ...$args)
    {
        // 检查最后一个参数是否是回调函数
        $callback = null;
        if (!empty($args) && is_callable(end($args))) {
            $callback = array_pop($args);
        }
        
        // 生成ACK ID
        $ackId = $this->session ? count($this->session->ackCallbacks) + 1 : 1;
        
        // 存储回调函数（同时在Session和EventHandler中都存储）
        if ($callback) {
            // 在Session中存储（用于内部引用）
            if ($this->session) {
                $this->session->ackCallbacks[$ackId] = $callback;
                
                // 设置超时
                if ($this->timeout !== null) {
                    $timeoutMs = $this->timeout;
                    $this->session->ackCallbacks[$ackId . '_timeout'] = time() + ($timeoutMs / 1000);
                }
            }
            
            // 在EventHandler中存储（用于实际调用）
            if ($this->server && $this->server->getEventHandler()) {
                $socket = ['id' => $this->sid, 'namespace' => $this->namespace];
                $this->server->getEventHandler()->storeAckCallback($socket, $this->namespace, $ackId, $callback);
            }
        }
        
        // 重置超时和排除房间
        $this->timeout = null;
        $this->exceptRooms = [];
        
        // 构建带ACK的事件包
        return $this->emitAckEvent($event, $ackId, ...$args);
    }

    /**
     * 批量发送多个事件 (优化性能)
     */
    public function emitMultiple(array $events)
    {
        foreach ($events as $event) {
            if (is_array($event) && count($event) >= 1) {
                $eventName = array_shift($event);
                $this->emit($eventName, ...$event);
            }
        }
        
        return $this;
    }

    /**
     * 发送压缩包 (性能优化)
     */
    public function emitCompressed($event, ...$args)
    {
        // 这里可以实现压缩逻辑
        return $this->emit($event, ...$args);
    }

    /**
     * 获取连接基本信息
     */
    public function getInfo()
    {
        return [
            'id' => $this->id,
            'sid' => $this->sid,
            'namespace' => $this->namespace,
            'rooms' => array_keys($this->rooms),
            'connected_at' => $this->session ? $this->session->created_at : null,
            'ip' => $this->connection ? $this->connection->getRemoteIp() : null
        ];
    }

    /**
     * 检查是否连接到指定命名空间
     */
    public function isConnected($namespace = null)
    {
        if ($namespace === null) {
            return !empty($this->sid) && $this->session !== null;
        }
        
        return $this->namespace === $namespace && $this->isConnected();
    }

    /**
     * 检查是否在指定房间中
     */
    public function inRoom($room)
    {
        return isset($this->rooms[$room]);
    }

    /**
     * 广播事件到除了自己以外的其他连接
     */
    public function broadcast()
    {
        // 创建一个排除当前socket的广播器
        $broadcaster = new SocketBroadcaster($this->server, $this);
        return $broadcaster->broadcast();
    }

    /**
     * 序列化方法（用于存储或传输）
     */
    public function serialize()
    {
        return [
            'sid' => $this->sid,
            'namespace' => $this->namespace,
            'rooms' => $this->rooms,
            'auth' => $this->auth,
            'headers' => $this->headers
        ];
    }

    /**
     * 反序列化方法（用于恢复socket实例）
     */
    public static function unserialize(array $data, $server = null)
    {
        $socket = new self($data['sid'], $data['namespace'], $server);
        $socket->rooms = $data['rooms'] ?? [];
        $socket->auth = $data['auth'] ?? null;
        $socket->headers = $data['headers'] ?? null;
        
        return $socket;
    }

    /**
     * 获取传输器类型 (兼容server.php中的调用)
     */
    public function getTransport()
    {
        // 如果有session对象，从其获取传输类型
        if ($this->session && isset($this->session->transport)) {
            return $this->session->transport;
        }
        return 'websocket'; // 默认传输类型
    }

    /**
     * 获取Socket ID (兼容server.php中的调用)
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * 魔术方法：支持链式调用
     */
    public function __call($method, $args)
    {
        // 转发到服务器实例（如果方法不存在）
        if ($this->server && method_exists($this->server, $method)) {
            return call_user_func_array([$this->server, $method], $args);
        }
        
        throw new \BadMethodCallException("方法 {$method} 不存在");
    }
}

/**
 * SocketBroadcaster - Socket广播辅助类
 * 用于socket->broadcast->emit()链式调用
 */
class SocketBroadcaster
{
    private $server;
    private $excludeSocket;
    private $targetRoom = null;

    /**
     * 构造函数
     */
    public function __construct($server, $excludeSocket = null)
    {
        $this->server = $server;
        $this->excludeSocket = $excludeSocket;
    }

    /**
     * 指定要广播到的房间
     */
    public function to($room)
    {
        $this->targetRoom = $room;
        return $this;
    }

    /**
     * 广播事件到除当前socket外的所有客户端
     */
    public function emit($event, ...$args)
    {
        if (!$this->server) {
            throw new \RuntimeException("服务器实例不存在");
        }

        echo "[SocketBroadcaster] 广播事件: {$event}";
        if ($this->targetRoom) {
            echo "，目标房间: {$this->targetRoom}\n";
        } else {
            echo "，排除当前socket\n";
        }

        // 如果有目标房间，发送到特定房间
        if ($this->targetRoom) {
            return $this->server->emitToRoom($this->targetRoom, $event, ...$args);
        } else {
            // 否则广播到所有连接，排除指定的socket
            array_push($args, $this->excludeSocket);
            return $this->server->broadcast($event, ...$args);
        }
    }
}