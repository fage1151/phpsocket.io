<?php

namespace PhpSocketIO;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

/**
 * SocketIOServer - Socket.IO v4服务器主类
 * 负责Socket.IO协议处理、事件分发和服务器管理
 * @package PhpSocketIO
 */
class SocketIOServer
{
    // 静态属性：存储全局服务器实例
    private static $instance = null;
    
    private $serverManager;         // 服务器配置管理器
    private $roomManager;           // 房间管理器
    private $middlewareHandler;     // 中间件处理器
    private $eventHandler;          // 事件处理器
    private $engineIoHandler;       // Engine.IO协议处理器
    private $pollingHandler;        // HTTP轮询处理器
    private $httpRequestHandler;    // HTTP请求处理器
    private $namespaceHandlers = []; // 命名空间处理器缓存
    private $sid = null;            // Socket ID (兼容server.php调用)
    
    /**
     * 存储每个Session的事件处理器缓存
     */
    private $sessionEventHandlers = [];
    
    /**
     * 会话到Socket实例的映射缓存
     */
    private $sessionSocketMap = [];

    public function __construct(string $listen, array $options = [])
    {
        // 初始化组件
        $this->serverManager = new ServerManager();
        $this->roomManager = new RoomManager();
        $this->middlewareHandler = new MiddlewareHandler();
        $this->engineIoHandler = new EngineIOHandler($options);
        $this->eventHandler = new EventHandler(['server' => $this]);
        $this->pollingHandler = new PollingHandler($this->serverManager, $this->engineIoHandler, $this->middlewareHandler);
        $this->httpRequestHandler = new HttpRequestHandler($this->serverManager, $this->pollingHandler, $this->engineIoHandler);
        
        // 设置依赖和回调
        $this->engineIoHandler->setEventHandler($this->eventHandler);
        $this->engineIoHandler->setRoomManager($this->roomManager);
        
        $this->engineIoHandler->setSocketIOMessageHandler(fn(string $message, $connection, $session) => 
            $this->handleSocketIOMessage($message, $connection, $session)
        );
        
        $this->engineIoHandler->setBinaryMessageHandler(fn(string $binaryData, $connection, $session) => 
            $this->handleSocketIOBinaryMessage($binaryData, $connection, $session)
        );
        
        // 配置和监听
        $this->serverManager->setConfig($options);
        
        // 解析监听地址并启动
        if (!preg_match('/^(?:http:\/\/)?([^:]+):(\d+)$/', $listen, $matches)) {
            throw new \Exception('Invalid listen format. Use "address:port" format.');
        }
        
        $this->listen((int)$matches[2], $matches[1], ['ssl' => ($options['ssl'] ?? [])]);
    }
    
    /**
     * 设置跨进程适配器
     */
    public function setAdapter($adapter, array $config = [])
    {
        $this->serverManager->setAdapter($adapter, $config);
    }
    
    /**
     * 获取Socket ID
     */
    public function getId()
    {
        return $this->sid;
    }
    
    /**
     * 检查是否启用集群模式
     */
    public function hasCluster()
    {
        return $this->serverManager->hasCluster();
    }

    /**
     * 声明事件监听器回调函数 (支持多参数)
     * @param string|'connection' $event 事件名称（如 'connection'、'message'、自定义事件）
     * @param callable $handler 事件处理器回调函数
     * @param string|'/' $namespace 命名空间（可选，默认 '/'）
     */
    public function on($event, callable $handler, string $namespace = '/', string $type = '@EVENT_HANDLER')
    {
        // 如果是connection事件，需要缓存处理器供EventHandler调用
        if ($event === 'connection') {
            if (!isset($this->namespaceHandlers[$namespace])) {
                $this->namespaceHandlers[$namespace] = [];
            }
            $this->namespaceHandlers[$namespace]['connection'] = $handler;
            echo "[socketio v4] 已缓存connection事件处理器，命名空间: {$namespace}\n";
        }
        
        return $this->eventHandler->on($event, $handler, $namespace);
    }
    
    /**
     * 获取Socket.IO回调函数（EventHandler需要调用）
     */
    public function getSocketIoCallback($event, $namespace = '/')
    {
        if ($event === 'connection' && isset($this->namespaceHandlers[$namespace]['connection'])) {
            echo "[socketio v4] 成功获取connection事件处理器，命名空间: {$namespace}\n";
            return $this->namespaceHandlers[$namespace]['connection'];
        }
        echo "[socketio v4] 未找到事件处理器: {$namespace}::{$event}\n";
        return null;
    }
    
    /**
     * 获取命名空间处理器（EventHandler需要调用）
     */
    public function getNamespaceHandlers()
    {
        return $this->namespaceHandlers;
    }
    
    /**
     * 检查是否有命名空间处理器（EventHandler需要调用）
     */
    public function hasNamespaceHandler($namespace = '/')
    {
        return isset($this->namespaceHandlers[$namespace]);
    }

    //========================================================================
    // Workerman事件接口
    //========================================================================
    
    /**
     * 当有客户端连接时触发（Workerman接口）
     */
    public function onConnect(TcpConnection $connection)
    {
        echo "[connect] new connection from " . $connection->getRemoteIp() . "\n";
    }

    /**
     * 当收到消息时触发（Workerman接口）
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $this->httpRequestHandler->handleMessage($connection, $data);
    }

    /**
     * 当连接关闭时触发（Workerman接口）
     */
    public function onClose(TcpConnection $connection)
    {
        echo "[close] connection closed\n";
        if (isset($connection->session)) {
            $session = $connection->session;
            
            // 触发所有命名空间的断开事件
            foreach ($session->namespaces as $namespace => $auth) {
                $this->eventHandler->triggerEvent($session, $namespace, 'disconnect', []);
            }
            
            // 清理会话资源
            Session::remove($session->sid);
        }
    }

    /**
     * 当发生错误时触发（Workerman接口）
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {
        echo "[error] connection error: {$code} - {$msg}\n";
    }

    //========================================================================
    // 服务器启动和控制方法
    //========================================================================

    /**
     * 启动Socket.IO服务器
     */
    public function listen(int $port = 8088, string $address = '0.0.0.0', array $context = []): void
    {
        // 提取SSL配置
        $sslConfig = $context['ssl'] ?? [];
        
        // 创建Workerman Worker实例，protocol始终为http，传递整个context
        $worker = new Worker("http://{$address}:{$port}", $context);
        $worker->name = 'SocketIO-Server';
        
        // 如果有SSL配置，设置transport为ssl
        if (!empty($sslConfig)) {
            $worker->transport = 'ssl';
        }
        
        // 绑定事件处理器
        $worker->onConnect = [$this, 'onConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $worker->onError = [$this, 'onError'];
        
        // 启动心跳定时器
        $worker->onWorkerStart = fn() => $this->startHeartbeat() && print "SocketIO server started\n";
    }

    /**
     * 高性能心跳机制 - 批量处理以避免阻塞
     */
    private function startHeartbeat()
    {
        $this->engineIoHandler->startHeartbeat();
    }
    
    /**
     * 处理Socket.IO协议消息
     * @param string $message Socket.IO消息
     * @param Workerman\Connection\TcpConnection $connection 连接对象
     * @param Session $session 会话对象
     */
    private function handleSocketIOMessage(string $message, $connection, $session): void
    {
        try {
            // 解析Socket.IO数据包并处理
            $packet = PacketParser::parseSocketIOPacket($message) ?: null;
            if (!$packet) {
                return;
            }
            
            $namespace = $packet['namespace'] ?? '/';
            $type = $packet['type'];
            $data = $packet['data'] ?? [];
            
            // 根据包类型处理
            match ($type) {
                'CONNECT' => $this->handleConnectPacket($connection, $session, $namespace, $data),
                'DISCONNECT' => $this->handleDisconnectPacket($connection, $session, $namespace),
                'EVENT', 'BINARY_EVENT' => $this->handleEventPacket(
                    $connection, 
                    $session, 
                    $namespace, 
                    $packet['event'] ?? 'unknown',
                    $packet['args'] ?? (is_array($data) ? $data : [$data]),
                    $packet['id'] ?? null
                ),
                'ACK', 'BINARY_ACK' => $this->handleAckPacket($connection, $session, $namespace, $data),
                'ERROR' => $this->handleErrorPacket($connection, $session, $namespace, $data),
            };
        } catch (\Exception $e) {
            echo "[error] 处理Socket.IO消息异常: " . $e->getMessage() . "\n";
            // 可以在这里添加更多错误处理逻辑，如记录日志等
        }
    }
    
    /**
     * 处理Socket.IO二进制消息
     */
    private function handleSocketIOBinaryMessage($binaryData, $connection, $session)
    {
        echo "[socketio] 收到二进制消息, 大小: " . strlen($binaryData) . " 字节\n";
        
        // 检查是否有待处理的占位符包
        if (isset($session->pendingBinaryPlaceholder)) {
            $placeholderInfo = $session->pendingBinaryPlaceholder;
            $placeholderPacket = $placeholderInfo['packet'];
            $placeholderNum = $placeholderInfo['num'] ?? 0;
            
            echo "[socketio] 结合占位符包处理二进制数据, placeholder_num={$placeholderNum}\n";
            
            // 使用 EngineIOHandler 处理二进制数据
            $this->engineIoHandler->processBinaryData($session, $placeholderPacket, $binaryData, $placeholderNum);
        } else {
            // 没有占位符包，直接处理二进制数据
            echo "[socketio] 没有待处理的占位符包，将二进制数据作为独立附件处理\n";
            // 这里可以添加独立二进制数据的处理逻辑
        }
    }
    
    /**
     * 处理连接包
     */
    private function handleConnectPacket($connection, $session, string $namespace, $authData): void
    {
        // 记录命名空间连接
        $session->namespaces[$namespace] = true;
        
        // 创建并缓存Socket实例
        $sessionKey = "{$session->sid}:{$namespace}";
        $socket = $this->sessionSocketMap[$sessionKey] ??= new Socket($session->sid, $namespace, $this);
        
        // 触发连接事件
        $this->eventHandler->triggerConnect(
            [
                'id' => $sessionKey,
                'session' => $session,
                'connection' => $connection,
                'namespace' => $namespace,
                'socket' => $socket
            ], 
            $namespace, 
            $this
        );
        
        // 发送连接确认包
        $sessionData = [
            'sid' => $session->sid,
            'upgrades' => [],
            'pingInterval' => $this->engineIoHandler->getPingInterval(),
            'pingTimeout' => $this->engineIoHandler->getPingTimeout(),
            'maxPayload' => 1000000
        ];
        
        $connectAck = $namespace === '/' 
            ? '40' . json_encode($sessionData)
            : '40' . $namespace . ',' . json_encode($sessionData);
        
        $session->send($connectAck);
    }
    
    /**
     * 处理断开连接包
     */
    private function handleDisconnectPacket($connection, $session, $namespace)
    {
        echo "[socketio] 断开命名空间连接: {$namespace}\n";
        
        // 移除命名空间记录
        unset($session->namespaces[$namespace]);
        
        // 触发断开事件
        $this->eventHandler->triggerEvent($session, $namespace, 'disconnect', []);
    }
    
    /**
     * 处理事件包
     */
    private function handleEventPacket($connection, $session, string $namespace, string $eventName, array $eventArgs = [], ?int $ackId = null): void
    {
        try {
            if (empty($eventName)) {
                return;
            }

            // 获取或创建Socket实例
            $sessionKey = "{$session->sid}:{$namespace}";
            $socket = $this->sessionSocketMap[$sessionKey] ??= new Socket($session->sid, $namespace, $this);
            
            // 确保Session对象包含connection属性并同步WebSocket状态
            if ($session->connection = $connection) {
                $session->transport = 'websocket';
                $session->isWs = true;
            }
            
            // 处理事件
            if (!$this->eventHandler->triggerEventWithAck($session, $namespace, $eventName, $eventArgs, $ackId)) {
                $this->processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId);
            }
        } catch (\Exception $e) {
            echo "[error] 处理事件包异常: " . $e->getMessage() . "\n";
            // 可以在这里添加更多错误处理逻辑，如记录日志等
        }
    }
    
    /**
     * 处理ACK包
     */
    private function handleAckPacket($connection, $session, $namespace, $data)
    {
        echo "[socketio] ACK包: {$namespace}\n";
        // TODO: 实现ACK回调处理
    }
    
    /**
     * 处理事件处理器
     * 统一处理Socket实例级别和EventHandler级别的事件处理器
     */
    private function processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId = null)
    {
        try {
            // 检查Socket实例级别的事件处理器（通过$socket->on注册的）
            $foundHandler = false;
            $handler = null;
            
            // 首先检查Socket实例级别的专用处理器
            if ($socket->hasEventHandler($eventName)) {
                $handler = $socket->getEventHandler($eventName);
            } else if (isset($this->eventHandler->namespaceHandlers[$namespace]['events'][$eventName])) {
                // 后备：通过EventHandler获取该命名空间的事件处理器
                $handler = $this->eventHandler->namespaceHandlers[$namespace]['events'][$eventName];
            }
            
            if ($handler) {
                // 构建参数（Socket实例只接收数据参数）
                $callArgs = EventHandler::buildHandlerArguments($handler, [
                    'id' => $session->sid,
                    'session' => $session,
                    'namespace' => $namespace,
                    'socket' => $socket
                ], $eventArgs, $namespace);
                
                // 如果有ACK ID，添加回调函数
                if ($ackId !== null) {
                    $callArgs[] = function($data) use ($session, $namespace, $ackId) {
                        // 发送ACK响应
                        $this->eventHandler->sendAck([
                            'id' => $session->sid,
                            'session' => $session,
                            'namespace' => $namespace
                        ], $namespace, $ackId, $data);
                    };
                }
                
                // 执行事件处理器
                $result = call_user_func_array($handler, $callArgs);
                
                // 如果有ACK ID且处理器返回了结果，发送ACK响应
                if ($ackId !== null && $result !== null) {
                    $this->eventHandler->sendAck([
                        'id' => $session->sid,
                        'session' => $session,
                        'namespace' => $namespace
                    ], $namespace, $ackId, $result);
                }
                
                $foundHandler = true;
            }
            
            return $foundHandler;
            
        } catch (\Exception $e) {
            echo "[socketio v4] 事件处理器异常: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    //========================================================================
    // 房间管理功能
    //========================================================================

    /**
     * 将连接加入房间
     */
    public function join($room, Session $session = null)
    {
        return $this->roomManager->join($room, $session);
    }

    /**
     * 将连接从房间移除
     */
    public function leave($room, Session $session = null)
    {
        return $this->roomManager->leave($room, $session);
    }

    /**
     * 向指定房间或Socket发送消息（链式调用）
     * 用法：$io->to($roomOrSocket)->emit('event', ...args)
     */
    public function to($roomOrSocket)
    {
        // 返回一个广播器对象，支持链式调用
        return new ServerBroadcaster($this, $roomOrSocket);
    }

    /**
     * 获取房间管理器实例
     */
    public function getRoomManager()
    {
        return $this->roomManager;
    }

    /**
     * 向指定房间发送消息（Socket.IO v4群发API）
     */
    public function emitToRoom($room, $event, ...$args)
    {
        $roomMembers = $this->roomManager->getRoomMembers($room);
        
        // 向房间内所有成员发送消息
        foreach ($roomMembers as $sid) {
            // 获取会话
            $session = Session::get($sid);
            if (!$session) continue;
            
            // 创建Socket实例并发送消息
            $socket = new Socket($sid, '/', $this);
            $socket->emit($event, ...$args);
        }
        
        return $this;
    }

    /**
     * 实例级别emit方法 - 向所有客户端广播消息
     * 用法：$io->emit('broadcast', '消息内容');
     */
    public function emit(string $event, mixed ...$args): self
    {
        return $this->broadcast($event, ...$args);
    }

    /**
     * 广播事件到所有连接（可排除指定socket）
     */
    public function broadcast(string $event, mixed ...$args): self
    {
        // 检查args中最后一个参数是否为socket实例（用于排除）
        $excludeSocket = null;
        if (!empty($args)) {
            $lastArg = end($args);
            if ($lastArg instanceof \PhpSocketIO\Socket) {
                $excludeSocket = array_pop($args);
            }
        }
        
        // 构建消息包一次，避免重复构建
        $socket = new Socket('', '/', $this);
        $messagePacket = $socket->buildEventPacket($event, $args);
        
        // 向所有会话发送消息
        $activeSessions = Session::all();
        foreach ($activeSessions as $sid => $session) {
            // 跳过排除的socket
            if ($excludeSocket && $sid === $excludeSocket->getId()) {
                continue;
            }
            
            // 直接发送消息包，避免重复创建Socket实例
            $session->send($messagePacket);
        }
        
        return $this;
    }

    //========================================================================
    // 中间件功能
    //========================================================================

    /**
     * 注册中间件
     */
    public function use(callable $middleware, string $namespace = '/')
    {
        return $this->middlewareHandler->use($middleware, $namespace);
    }
    
    /**
     * 获取适配器实例
     */
    public function getAdapter()
    {
        return $this->serverManager ? $this->serverManager->getAdapter() : null;
    }
    
    /**
     * 获取EventHandler实例
     */
    public function getEventHandler()
    {
        return $this->eventHandler;
    }
    
    /**
     * 获取ServerManager实例
     */
    public function getServerManager()
    {
        return $this->serverManager;
    }
    
    /**
     * 设置全局服务器实例（用于静态调用）
     */
    public static function setInstance(SocketIOServer $instance)
    {
        self::$instance = $instance;
    }
    
    /**
     * 获取全局服务器实例
     */
    public static function getInstance()
    {
        return self::$instance;
    }
    
    /**
     * 获取所有 Socket 实例 (v4.0.0+)
     * 返回匹配的 Socket 实例数组
     */
    public function fetchSockets($namespace = '/')
    {
        $sockets = [];
        $activeSessions = Session::all();
        
        foreach ($activeSessions as $sid => $session) {
            // 检查会话是否已连接到指定命名空间
            if (isset($session->namespaces[$namespace]) && $session->namespaces[$namespace]) {
                $sessionKey = $sid . ':' . $namespace;
                if (isset($this->sessionSocketMap[$sessionKey])) {
                    $socket = $this->sessionSocketMap[$sessionKey];
                    $sockets[] = $socket;
                } else {
                    // 如果没有缓存，创建新的 Socket 实例
                    $socket = new Socket($sid, $namespace, $this);
                    $socket->session = $session;
                    $sockets[] = $socket;
                }
            }
        }
        
        echo "[SocketIOServer] fetchSockets 返回 " . count($sockets) . " 个 Socket 实例\n";
        return $sockets;
    }
    
    /**
     * 获取指定命名空间的 Socket.IO 实例
     * 用法：$io->of('/chat')->emit('event', 'data');
     */
    public function of($namespace = '/')
    {
        return new NamespaceEmitter($this, $namespace);
    }
    
}

/**
 * NamespaceEmitter - 命名空间发射器
 * 用于向指定命名空间的所有客户端发送消息
 */
class NamespaceEmitter
{
    private $server;
    private $namespace;
    
    public function __construct($server, $namespace)
    {
        $this->server = $server;
        $this->namespace = $namespace;
    }
    
    /**
     * 向指定命名空间的所有客户端发送消息
     */
    public function emit($event, ...$args)
    {
        // 获取指定命名空间的所有socket实例
        $sockets = $this->server->fetchSockets($this->namespace);
        
        // 向每个socket发送消息
        foreach ($sockets as $socket) {
            $socket->emit($event, ...$args);
        }
        
        return $this;
    }
    
    /**
     * 向指定房间发送消息
     */
    public function to($room)
    {
        return new RoomEmitter($this->server, $this->namespace, $room);
    }
}

/**
 * RoomEmitter - 房间发射器
 * 用于向指定命名空间的指定房间发送消息
 */
class RoomEmitter
{
    private $server;
    private $namespace;
    private $room;
    
    public function __construct($server, $namespace, $room)
    {
        $this->server = $server;
        $this->namespace = $namespace;
        $this->room = $room;
    }
    
    /**
     * 向指定房间的所有客户端发送消息
     */
    public function emit($event, ...$args)
    {
        // 获取指定命名空间的所有socket实例
        $sockets = $this->server->fetchSockets($this->namespace);
        
        // 向在指定房间的socket发送消息
        foreach ($sockets as $socket) {
            if ($socket->inRoom($this->room)) {
                $socket->emit($event, ...$args);
            }
        }
        
        return $this;
    }
}