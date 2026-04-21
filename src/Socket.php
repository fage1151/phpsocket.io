<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Workerman\Connection\TcpConnection;

/**
 * Socket类 - 封装Socket.IO客户端连接接口
 * 提供事件收发、房间管理、连接控制等功能
 * @package PhpSocketIO
 */
class Socket
{
    public ?string $sid;        // Session ID
    public string $namespace;   // 命名空间
    public ?SocketIOServer $server; // SocketIO服务器实例
    public ?TcpConnection $connection; // 连接对象
    public ?Session $session;   // 会话对象
    public mixed $auth;         // 认证信息
    public mixed $handshake;    // 握手信息
    public mixed $headers;      // HTTP头信息（如果有）
    public array $data = [];    // 任意数据对象 (v4.0.0+)

    public ?Broadcaster $broadcast = null;

    /**
     * 魔术方法，保持兼容性
     */
    public function __get(string $name): mixed
    {
        if ($name === 'id') {
            return $this->sid;
        }
        return null;
    }



    /**
     * 构造函数
     */
    public function __construct(?string $sid = null, string $namespace = '/', ?SocketIOServer $server = null, ?TcpConnection $connection = null)
    {
        $this->sid = $sid;
        $this->namespace = $namespace;
        $this->server = $server;
        $this->connection = $connection;
        $this->session = Session::get($sid);
        
        // 从session中获取握手信息和data
        if ($this->session) {
            $this->handshake = $this->session->handshake;
            $this->data = &$this->session->data; // 引用，保持同步
        }
        
        // 初始化broadcast属性，使用统一的Broadcaster
        $this->broadcast = new Broadcaster($server, $namespace, $this);
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
        $valid = true;
        $len = strlen($event);
        for ($i = 0; $i < $len; $i++) {
            $char = $event[$i];
            if (!ctype_alnum($char) && $char !== '_' && $char !== '.') {
                $valid = false;
                break;
            }
        }
        
        if (!$valid) {
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
        // 构建二进制事件包（Socket.IO v4标准格式）
        $binaryCount = count($binaryAttachments);
        $namespacePart = $this->namespace !== '/' ? $this->namespace . ',' : '';
        $eventData = array_merge([$event], $processedArgs);
        $socketIOPacket = json_encode($eventData);
        $packetData = '45' . $binaryCount . '-' . $namespacePart . $socketIOPacket;
        
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
        $namespacePart = $this->namespace !== '/' ? $this->namespace . ',' : '';
        $eventData = array_merge([$event], $args);
        $socketIOPacket = json_encode($eventData);
        // Socket.IO v4 标准格式：42[namespace,][ackId][event, args]
        $packetData = '42' . $namespacePart . $ackId . $socketIOPacket;
        
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
        
        // 快速检查1：包含 null 字节 → 二进制
        if (strpos($data, "\x00") !== false) {
            return true;
        }
        
        // 快速检查2：非可打印字符 → 二进制
        if (!ctype_print($data)) {
            return true;
        }
        
        $length = strlen($data);
        
        // 快速检查3：较短的数据（<100字节）假设不是二进制
        if ($length < 100) {
            // 尝试快速编码检查
            return @json_encode($data) === false;
        }
        
        // 对于较长数据，只检查前 100 个字符
        $controlCharCount = 0;
        $checkLength = min($length, 100);
        
        for ($i = 0; $i < $checkLength; $i++) {
            $char = ord($data[$i]);
            // 快速判断控制字符
            if ($char < 32 && $char !== 9 && $char !== 10 && $char !== 13) {
                $controlCharCount++;
            }
        }
        
        // 快速阈值判断（不做乘法）
        if ($controlCharCount * 10 > $checkLength) {
            return true;
        }
        
        // 最后检查 UTF-8 编码
        return @json_encode($data) === false;
    }

    /**
     * 注册事件监听器 (Socket.IO v4标准接口)
     */
    public function on(string $event, callable $callback): self
    {
        if (!$this->server) {
            throw new \RuntimeException("Socket实例未关联到服务器");
        }
        
        // 只注册到EventHandler，避免重复
        $this->server->on($event, $callback, $this->namespace);
        
        return $this;
    }
    
    /**
     * 获取Socket实例级别的事件处理器
     */
    public function getEventHandler(string $event): mixed
    {
        // 从EventHandler获取
        if ($this->server) {
            $eventHandler = $this->server->getEventHandler();
            if (isset($eventHandler->namespaceHandlers[$this->namespace]['events'][$event])) {
                return $eventHandler->namespaceHandlers[$this->namespace]['events'][$event];
            }
        }
        return null;
    }
    
    /**
     * 检查Socket实例是否存在特定事件处理器
     */
    public function hasEventHandler(string $event): bool
    {
        // 从EventHandler检查
        if ($this->server) {
            $eventHandler = $this->server->getEventHandler();
            return isset($eventHandler->namespaceHandlers[$this->namespace]['events'][$event]);
        }
        return false;
    }
    
    /**
     * 获取所有事件处理器（用于实例复用）
     */
    public function getAllEventHandlers(): array
    {
        // 从EventHandler获取
        if ($this->server) {
            $eventHandler = $this->server->getEventHandler();
            return $eventHandler->namespaceHandlers[$this->namespace]['events'] ?? [];
        }
        return [];
    }
    
    /**
     * 设置事件处理器（用于实例复用）
     */
    public function setEventHandlers(array $handlers): void
    {
        // 不建议直接设置，通过on()方法注册
        foreach ($handlers as $event => $callback) {
            $this->on($event, $callback);
        }
    }

    /**
     * 加入房间 (Socket.IO v4群发功能)
     */
    public function join(string $room): self
    {
        if (empty($room)) {
            throw new \InvalidArgumentException("房间名称不能为空");
        }
        
        if ($this->session && $this->server) {
            $this->server->getRoomManager()->join($room, $this->session);
        }
        
        return $this;
    }

    /**
     * 离开房间
     */
    public function leave(string $room): self
    {
        if ($this->session && $this->server) {
            $this->server->getRoomManager()->leave($room, $this->session);
        }
        
        return $this;
    }

    /**
     * 指定房间进行广播 (链式调用)
     * 例如: socket.to('room1').emit('message', 'Hello')
     */
    public function to(string|array $room): Broadcaster
    {
        // 使用统一的Broadcaster
        $broadcaster = new Broadcaster($this->server, $this->namespace, $this);
        return $broadcaster->to($room);
    }

    /**
     * 断开连接
     */
    public function disconnect(bool $close = false): self
    {
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
     * 排除特定房间的广播修饰符 (v4.0.0+)
     */
    public function except(string|array $room): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->namespace, $this);
        return $broadcaster->except($room);
    }

    /**
     * 带ACK的发送 (Promise风格, v4.6.0+)
     * 注意：PHP不支持Promise，这里使用回调函数方式
     */
    public function emitWithAck(string $event, mixed ...$args): self
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
            }
            
            // 在EventHandler中存储（用于实际调用）
            if ($this->server && $this->server->getEventHandler()) {
                $socket = ['id' => $this->sid, 'namespace' => $this->namespace];
                $this->server->getEventHandler()->storeAckCallback($socket, $this->namespace, $ackId, $callback);
            }
        }
        

        
        // 构建带ACK的事件包
        return $this->emitAckEvent($event, $ackId, ...$args);
    }

    /**
     * 批量发送多个事件 (优化性能)
     */
    public function emitMultiple(array $events): self
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
    public function emitCompressed(string $event, mixed ...$args): self
    {
        // 这里可以实现压缩逻辑
        return $this->emit($event, ...$args);
    }

    /**
     * 获取连接基本信息
     */
    public function getInfo(): array
    {
        $rooms = [];
        if ($this->session && $this->server) {
            $rooms = $this->server->getRoomManager()->getSessionRooms($this->sid);
        }
        
        return [
            'id' => $this->id,
            'sid' => $this->sid,
            'namespace' => $this->namespace,
            'rooms' => $rooms,
            'connected_at' => $this->session ? $this->session->created_at : null,
            'ip' => $this->connection ? $this->connection->getRemoteIp() : null
        ];
    }

    /**
     * 检查是否连接到指定命名空间
     */
    public function isConnected(?string $namespace = null): bool
    {
        if ($namespace === null) {
            return !empty($this->sid) && $this->session !== null;
        }
        
        return $this->namespace === $namespace && $this->isConnected();
    }

    /**
     * 检查是否在指定房间中
     */
    public function inRoom(string $room): bool
    {
        if ($this->session && $this->server) {
            return $this->server->getRoomManager()->isInRoom($this->sid, $room);
        }
        return false;
    }

    /**
     * 广播事件到除了自己以外的其他连接
     */
    public function broadcast(): ?Broadcaster
    {
        return $this->broadcast;
    }

    /**
     * 序列化方法（用于存储或传输）
     */
    public function serialize(): array
    {
        $rooms = [];
        if ($this->session && $this->server) {
            $roomNames = $this->server->getRoomManager()->getSessionRooms($this->sid);
            foreach ($roomNames as $roomName) {
                $rooms[$roomName] = true;
            }
        }
        
        return [
            'sid' => $this->sid,
            'namespace' => $this->namespace,
            'rooms' => $rooms,
            'auth' => $this->auth,
            'headers' => $this->headers
        ];
    }

    /**
     * 反序列化方法（用于恢复socket实例）
     */
    public static function unserialize(array $data, ?SocketIOServer $server = null): self
    {
        $socket = new self($data['sid'], $data['namespace'], $server);
        $socket->auth = $data['auth'] ?? null;
        $socket->headers = $data['headers'] ?? null;
        
        // 恢复房间（如果有房间数据）
        if (isset($data['rooms']) && is_array($data['rooms'])) {
            if ($socket->session && $server) {
                foreach (array_keys($data['rooms']) as $roomName) {
                    $server->getRoomManager()->join($roomName, $socket->session);
                }
            }
        }
        
        return $socket;
    }

    /**
     * 获取传输器类型 (兼容server.php中的调用)
     */
    public function getTransport(): string
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
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * 魔术方法：支持链式调用
     */
    public function __call(string $method, array $args): mixed
    {
        // 转发到服务器实例（如果方法不存在）
        if ($this->server && method_exists($this->server, $method)) {
            return call_user_func_array([$this->server, $method], $args);
        }
        
        throw new \BadMethodCallException("方法 {$method} 不存在");
    }
}