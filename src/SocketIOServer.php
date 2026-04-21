<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Socket.IO v4服务器主类
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
    private $logger;                // PSR-3 日志记录器
    
    /**
     * 会话到Socket实例的映射缓存
     */
    private $sessionSocketMap = [];

    public function __construct(string $listen, array $options = [])
    {
        // 初始化日志器
        $this->logger = new Logger($options['log_level'] ?? LogLevel::INFO);
        
        // 初始化组件
        $this->serverManager = new ServerManager();
        $this->roomManager = new RoomManager();
        $this->middlewareHandler = new MiddlewareHandler();
        $this->engineIoHandler = new EngineIOHandler($options);
        $this->eventHandler = new EventHandler(['server' => $this]);
        $this->pollingHandler = new PollingHandler($this->serverManager, $this->engineIoHandler);
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
        // 移除可能的 http:// 前缀
        if (strpos($listen, 'http://') === 0) {
            $listen = substr($listen, 7);
        }
        // 查找冒号位置
        $colonPos = strpos($listen, ':');
        if ($colonPos === false) {
            throw new \Exception('Invalid listen format. Use "address:port" format.');
        }
        $address = substr($listen, 0, $colonPos);
        $portStr = substr($listen, $colonPos + 1);
        if (!ctype_digit($portStr)) {
            throw new \Exception('Invalid listen format. Use "address:port" format.');
        }
        
        $this->listen((int)$portStr, $address, ['ssl' => ($options['ssl'] ?? [])]);
    }
    
    /**
     * 设置跨进程适配器
     */
    public function setAdapter(\PhpSocketIO\Adapter\AdapterInterface $adapter)
    {
        $this->serverManager->setAdapter($adapter);
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
    public function isClusterEnabled(): bool
    {
        return $this->serverManager->isClusterEnabled();
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
        }
        
        return $this->eventHandler->on($event, $handler, $namespace);
    }
    
    /**
     * 获取Socket.IO回调函数（EventHandler需要调用）
     */
    public function getSocketIoCallback($event, $namespace = '/')
    {
        if ($event === 'connection' && isset($this->namespaceHandlers[$namespace]['connection'])) {
            return $this->namespaceHandlers[$namespace]['connection'];
        }
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
    // 日志相关方法
    //========================================================================
    
    /**
     * 获取日志器
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
    
    /**
     * 设置日志器
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
    
    /**
     * 设置日志级别
     * @param string $level PSR-3 日志级别
     */
    public function setLogLevel(string $level): void
    {
        if (method_exists($this->logger, 'setLevel')) {
            $this->logger->setLevel($level);
        }
    }

    //========================================================================
    // Workerman事件接口
    //========================================================================
    
    /**
     * 当有客户端连接时触发（Workerman接口）
     */
    public function onConnect(TcpConnection $connection)
    {
        $this->logger->info('New client connection established', [
            'remote_ip' => $connection->getRemoteIp(),
            'remote_port' => $connection->getRemotePort()
        ]);
    }

    /**
     * 当收到消息时触发（Workerman接口）
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $dataLength = is_string($data) ? strlen($data) : 
                     (is_object($data) && method_exists($data, '__toString') ? 
                     strlen((string)$data) : 'object');
        
        $this->logger->debug('Received message from client', [
            'remote_ip' => $connection->getRemoteIp(),
            'data_length' => $dataLength,
            'data_type' => gettype($data)
        ]);
        $this->httpRequestHandler->handleMessage($connection, $data);
    }

    /**
     * 当连接关闭时触发（Workerman接口）
     */
    public function onClose(TcpConnection $connection)
    {
        if (isset($connection->session)) {
            $session = $connection->session;
            
            $this->logger->info('Client disconnecting', [
                'sid' => $session->sid
            ]);
            
            // 清理房间
            $this->roomManager->removeSession($session->sid);
            
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
        
        // 设置worker数量
        $workerCount = $this->serverManager->getWorkerCount();
        if ($workerCount > 1) {
            $worker->count = $workerCount;
        }
        
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
        $worker->onWorkerStart = function() use ($address, $port, $sslConfig) {
            $this->startHeartbeat();
            $this->logger->info('Socket.IO server started', [
                'address' => $address,
                'port' => $port,
                'ssl' => !empty($sslConfig)
            ]);
        };
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
            
            // 处理二进制事件：先存储占位符，等待二进制附件
            if ($type === 'BINARY_EVENT' && isset($packet['binaryCount']) && $packet['binaryCount'] > 0) {
                $this->logger->debug('Received binary event packet, waiting for attachments', [
                    'binary_count' => $packet['binaryCount'],
                    'sid' => $session->sid
                ]);
                $session->pendingBinaryPlaceholder = [
                    'packet' => $message,
                    'timestamp' => time()
                ];
                $session->pendingBinaryCount = $packet['binaryCount'];
                // 先不处理事件，等待二进制附件到达后再一起处理
                return;
            }
            
            // 根据包类型处理
            match ($type) {
                'CONNECT' => $this->handleConnectPacket($connection, $session, $namespace, $data),
                'DISCONNECT' => $this->handleDisconnectPacket($connection, $session, $namespace),
                'EVENT', 'BINARY_EVENT' => $this->handleEventPacket(
                    $connection, 
                    $session, 
                    $namespace, 
                    $packet['event'] ?? 'unknown',
                    $packet['data'] ?? (is_array($data) ? $data : [$data]),
                    $packet['id'] ?? null
                ),
                'ACK', 'BINARY_ACK' => $this->handleAckPacket($connection, $session, $namespace, $data, $packet['id'] ?? null),
                'ERROR' => $this->handleErrorPacket($connection, $session, $namespace, $data),
            };
        } catch (\Exception $e) {
            $this->logger->error('Error processing Socket.IO message', [
                'exception' => $e->getMessage(),
                'sid' => $session->sid
            ]);
        }
    }
    
    /**
     * 处理Socket.IO二进制消息
     */
    private function handleSocketIOBinaryMessage($binaryData, $connection, $session)
    {
        $this->logger->debug('Received binary message', [
            'size' => strlen($binaryData),
            'sid' => $session->sid
        ]);
        
        // 检查是否有待处理的占位符包
        if (isset($session->pendingBinaryPlaceholder) && $session->pendingBinaryCount > 0) {
            $placeholderInfo = $session->pendingBinaryPlaceholder;
            $placeholderPacket = $placeholderInfo['packet'];
            
            // 收集二进制附件
            $attachmentIndex = count($session->pendingBinaryAttachments);
            $session->pendingBinaryAttachments[$attachmentIndex] = $binaryData;
            $this->logger->debug('Collected binary attachment', [
                'index' => $attachmentIndex,
                'total' => $session->pendingBinaryCount,
                'sid' => $session->sid
            ]);
            
            // 检查是否所有附件都已收到
            if (count($session->pendingBinaryAttachments) >= $session->pendingBinaryCount) {
                $this->logger->debug('All binary attachments received, processing complete event', [
                    'sid' => $session->sid
                ]);
                
                // 合并处理所有附件
                $this->combineAndProcessBinaryAttachments($session, $connection);
                
                // 清理
                $session->pendingBinaryAttachments = [];
                $session->pendingBinaryPlaceholder = null;
                $session->pendingBinaryCount = 0;
            }
        } else {
            // 没有占位符包，直接处理二进制数据
            $this->logger->debug('No pending placeholder packet, treating as standalone attachment', [
                'sid' => $session->sid
            ]);
            // 这里可以添加独立二进制数据的处理逻辑
        }
    }
    
    /**
     * 合并所有二进制附件并处理完整事件
     */
    private function combineAndProcessBinaryAttachments(Session $session, $connection): void
    {
        $placeholderInfo = $session->pendingBinaryPlaceholder;
        $originalPacket = $placeholderInfo['packet'] ?? null;
        
        if (!$originalPacket) {
            $this->logger->error('No original packet found for binary placeholder', [
                'sid' => $session->sid
            ]);
            return;
        }
        
        // 解析占位符包
        $packet = PacketParser::parseSocketIOPacket($originalPacket);
        if (!$packet) {
            $this->logger->error('Failed to parse placeholder packet', [
                'sid' => $session->sid
            ]);
            return;
        }
        
        // 替换二进制占位符
        $packet = PacketParser::replaceBinaryPlaceholders($packet, $session->pendingBinaryAttachments);
        
        $this->logger->debug('Processing complete binary event', [
            'event' => $packet['event'],
            'sid' => $session->sid
        ]);
        
        // 处理事件
        $namespace = $packet['namespace'] ?? '/';
        $this->handleEventPacket(
            $connection,
            $session,
            $namespace,
            $packet['event'] ?? 'unknown',
            $packet['data'] ?? (isset($packet['data']) ? (is_array($packet['data']) ? $packet['data'] : [$packet['data']]) : []),
            $packet['id'] ?? null
        );
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
        
        // 构建 Socket.IO v4 标准连接确认包
        $socketIoPacket = PacketParser::buildSocketIOPacket('CONNECT', [
            'namespace' => $namespace,
            'data' => [
                'sid' => $session->sid
            ]
        ]);
        
        // 包装为 Engine.IO 4 类型包发送
        $engineIoPacket = '4' . $socketIoPacket;
        $session->send($engineIoPacket);
    }
    
    /**
     * 处理断开连接包
     */
    private function handleDisconnectPacket($connection, $session, $namespace)
    {
        $this->logger->debug('Disconnecting namespace', [
            'namespace' => $namespace,
            'sid' => $session->sid
        ]);
        
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
            $session->connection = $connection;
            $session->transport = 'websocket';
            $session->isWs = true;
            
            // 处理事件
            if (!$this->eventHandler->triggerEventWithAck($session, $namespace, $eventName, $eventArgs, $ackId)) {
                $this->processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error processing event packet', [
                'exception' => $e->getMessage(),
                'sid' => $session->sid,
                'namespace' => $namespace,
                'event' => $eventName
            ]);
        }
    }
    
    /**
     * 处理ACK包
     */
    private function handleAckPacket($connection, $session, $namespace, $data, ?int $ackId = null): void
    {
        $this->logger->debug('Received ACK packet', [
            'namespace' => $namespace,
            'ackId' => $ackId,
            'sid' => $session->sid
        ]);
        
        if ($ackId === null) {
            return;
        }
        
        // 优先从 EventHandler 查找 ACK 回调
        if ($this->eventHandler && method_exists($this->eventHandler, 'executeAckCallback')) {
            $this->eventHandler->executeAckCallback($ackId, $data);
            return;
        }
        
        // 也从 Session 中查找 ACK 回调
        if (isset($session->ackCallbacks) && is_array($session->ackCallbacks)) {
            if (isset($session->ackCallbacks[$ackId]) && is_callable($session->ackCallbacks[$ackId])) {
                $callback = $session->ackCallbacks[$ackId];
                $callback(...$data);
                unset($session->ackCallbacks[$ackId]);
            }
        }
    }
    
    /**
     * 处理事件处理器
     */
    private function processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId = null)
    {
        try {
            $this->logger->debug('Processing event handlers', [
                'namespace' => $namespace,
                'eventName' => $eventName,
                'sid' => $session->sid
            ]);
            
            $foundHandler = false;
            // 直接从EventHandler获取该命名空间的事件处理器
            $handler = null;
            if (isset($this->eventHandler->namespaceHandlers[$namespace]['events'][$eventName])) {
                $handler = $this->eventHandler->namespaceHandlers[$namespace]['events'][$eventName];
                $this->logger->debug('Found EventHandler level handler', [
                    'namespace' => $namespace,
                    'eventName' => $eventName,
                    'sid' => $session->sid
                ]);
            }
            
            if ($handler) {
                $callArgs = EventHandler::buildHandlerArguments($handler, [
                    'id' => $session->sid,
                    'session' => $session,
                    'namespace' => $namespace,
                    'socket' => $socket
                ], $eventArgs, $namespace);
                
                if ($ackId !== null) {
                    $callArgs[] = function($data) use ($session, $namespace, $ackId) {
                        $this->eventHandler->sendAck([
                            'id' => $session->sid,
                            'session' => $session,
                            'namespace' => $namespace
                        ], $namespace, $ackId, $data);
                    };
                }
                
                $result = call_user_func_array($handler, $callArgs);
                
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
            $this->logger->error('Error in event handler', [
                'exception' => $e->getMessage(),
                'namespace' => $namespace,
                'eventName' => $eventName,
                'sid' => $session->sid
            ]);
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
        // 返回统一的Broadcaster对象
        $broadcaster = new Broadcaster($this);
        return $broadcaster->to($roomOrSocket);
    }

    /**
     * 获取房间管理器实例
     */
    public function getRoomManager()
    {
        return $this->roomManager;
    }

    /**
     * 向所有客户端广播消息
     * 用法：$io->emit('broadcast', '消息内容');
     */
    public function emit(string $event, mixed ...$args): self
    {
        $broadcaster = new Broadcaster($this);
        $broadcaster->emit($event, ...$args);
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
        
        $this->logger->debug('fetchSockets returned socket instances', [
            'count' => count($sockets),
            'namespace' => $namespace
        ]);
        return $sockets;
    }
    
    /**
     * 获取指定命名空间的 Socket.IO 实例
     * 用法：$io->of('/chat')->emit('event', 'data');
     */
    public function of($namespace = '/')
    {
        return new Broadcaster($this, $namespace);
    }
    
}