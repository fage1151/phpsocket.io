<?php

namespace PhpSocketIO;

/**
 * Socket.IO客户端Socket实例 - 实现消息分发功能
 * 符合Socket.IO v4服务端标准API
 */
class ClientSocket
{
    private $id;              // Socket ID
    private $serverManager;   // 服务器管理器
    private $roomManager;     // 房间管理器  
    private $namespaces = []; // 命名空间处理器
    private $adapter;         // 适配器

    /**
     * 构造函数
     */
    public function __construct($id, ServerManager $serverManager, RoomManager $roomManager, EventHandler $eventHandler, $adapter = null)
    {
        $this->id = $id;
        $this->serverManager = $serverManager;
        $this->roomManager = $roomManager;
        $this->eventHandler = $eventHandler; // 添加EventHandler引用
        $this->adapter = $adapter;
    }

    /**
     * Socket.IO v4单发消息
     * @param string $event 事件名
     * @param mixed $data 数据
     */
    public function emit($event, $data = null)
    {
        if ($this->adapter) {
            // 单发消息到当前socket - Socket.IO v4协议修复
            $this->adapter->send($this->id, [
                'type' => 2, // Socket.IO v4 EVENT类型码
                'event' => $event,
                'data' => $data
            ]);
        } else {
            // 非集群模式直接处理
            $this->sendToSocket($this->id, $event, $data);
        }
        return $this;
    }

    /**
     * Socket.IO v4群发消息（指定房间）
     * @param string|array $rooms 房间名
     */
    public function to($rooms)
    {
        if (!is_array($rooms)) {
            $rooms = [$rooms];
        }
        
        // 创建群发器实例
        return new SocketEmitter($this, $rooms, 'room');
    }

    /**
     * Socket.IO v4广播消息（除自己外所有人）
     */
    public function broadcast()
    {
        return new SocketEmitter($this, [$this->id], 'broadcast');
    }

    /**
     * 获取Socket ID
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * 获取服务器管理器（用于与适配器交互）
     */
    public function getServerManager()
    {
        return $this->serverManager;
    }

    /**
     * 获取房间管理器
     */
    public function getRoomManager()
    {
        return $this->roomManager;
    }

    /**
     * 获取事件处理器
     */
    public function getEventHandler()
    {
        return $this->eventHandler;
    }

    /**
     * 加入房间
     */
    public function join($room)
    {
        return $this->roomManager->join($room, $this);
    }

    /**
     * 离开房间
     */
    public function leave($room)
    {
        return $this->roomManager->leave($room, $this);
    }

    /**
     * 直接发送消息到socket（单发实现）
     */
    private function sendToSocket($sid, $event, $data)
    {
        // 实际的消息发送实现 - Socket.IO v4协议优化
        $packet = PacketParser::buildSocketIOPacket('EVENT', [
            'event' => $event,
            'data' => $data
        ]);
        
        // 构建socket信息数组
        $socketInfo = [
            'id' => $sid,
            'namespace' => '/',
            'socket' => $this
        ];
        
        // 调用EventHandler进行事件分发（确保触发客户端事件处理器）
        $this->eventHandler->handlePacket($packet, $socketInfo);
        
        return true;
    }

    /**
     * Socket.IO v4标准事件处理器注册
     */
    public function on($event, callable $callback)
    {
        // Socket.IO v4事件注册：标准调用EventHandler::on方法
        return $this->eventHandler->on($event, $callback, '/');
    }

    /**
     * 断开连接
     */
    public function disconnect()
    {
        if ($this->adapter) {
            $this->adapter->delAll($this->id);
        }
        return $this;
    }
}

/**
 * Socket消息发射器 - 实现群发和广播功能
 */
class SocketEmitter
{
    private $socket;
    private $targets;
    private $type; // room | broadcast

    public function __construct(ClientSocket $socket, $targets, $type)
    {
        $this->socket = $socket;
        $this->targets = $targets;
        $this->type = $type;
    }

    /**
     * 发送消息（Socket.IO v4标准API）
     */
    public function emit($event, ...$args)
    {
        if ($this->type === 'room') {
            // 群发到房间 - 使用RoomManager实现
            foreach ($this->targets as $room) {
                $this->emitToRoom($room, $event, ...$args);
            }
        } else {
            // 广播（排除指定目标）- 需要会话级的广播实现
            echo "[socketio] Broadcast feature requires session-level implementation\n";
        }
        return $this->socket;
    }
    
    /**
     * 发送消息到指定房间
     */
    private function emitToRoom($room, $event, ...$args)
    {
        // 获取房间成员列表
        $roomMembers = $this->socket->getRoomManager()->getRoomMembers($room);
        
        // 向房间内所有成员发送消息
        foreach ($roomMembers as $sid) {
            // 排除自己（如果是广播）
            if ($this->type === 'broadcast' && $sid === $this->socket->getId()) {
                continue;
            }
            
            // 创建目标socket实例并发送消息
            if ($this->socket->getServerManager()->hasCluster()) {
                // 集群模式：通过集群适配器发送
                $this->sendToRoomViaCluster($sid, $event, ...$args);
            } else {
                // 单机模式：直接发送
                $this->sendToRoomDirect($sid, $event, ...$args);
            }
        }
    }
    
    /**
     * 集群模式：通过适配器发送到房间
     */
    private function sendToRoomViaCluster($sid, $event, ...$args)
    {
        // 使用集群适配器发送消息
        $adapter = $this->socket->getServerManager()->getAdapter();
        if ($adapter && method_exists($adapter, 'send')) {
            // Socket.IO v4协议修复：数据必须作为单个数组参数传递
            // 错误格式：{data: ["arg1", "arg2"]} -> 事件处理器接收两个参数
            // 正确格式：{data: ["arg1", "arg2"]} -> 事件处理器接收单个数组参数
            
            $packet = [
                'type' => 'EVENT',
                'event' => $event,
                'data' => $args,  // 保持数组格式（将由EventHandler正确解析为单个参数）
                'namespace' => '/'
            ];
            
            // 适配器发送需要JSON字符串格式
            $jsonPacket = json_encode($packet);
            echo "[socket_emitter] 集群模式发送: " . $jsonPacket . "\n";
            $adapter->send($sid, $jsonPacket);
        }
    }
    
    /**
     * 单机模式：直接发送到房间
     */
    private function sendToRoomDirect($sid, $event, ...$args)
    {
        // Session::sendToSession方法期望接收数组类型的数据包
        // Socket.IO v4协议修复：统一使用'data'字段名称
        $packet = [
            'type' => 'EVENT', 
            'event' => $event,
            'data' => $args,  // 事件处理器将接收到单个数组参数
            'namespace' => '/'
        ];
        
        echo "[socket_emitter] 单机模式发送: " . json_encode($packet) . "\n";
        // 直接使用Session::sendToSession方法发送消息
        Session::sendToSession($sid, $packet);
    }
}