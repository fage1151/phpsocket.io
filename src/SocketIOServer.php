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
        $this->initializeLogger($options);
        $this->initializeComponents($options);
        $this->configureComponents();
        $this->parseAndStart($listen, $options);
    }
    
    /**
     * 初始化日志器
     */
    private function initializeLogger(array $options): void
    {
        $this->logger = new Logger($options['logLevel'] ?? $options['log_level'] ?? LogLevel::INFO);
    }
    
    /**
     * 初始化组件
     */
    private function initializeComponents(array $options): void
    {
        $this->serverManager = new ServerManager();
        $this->roomManager = new RoomManager();
        $this->middlewareHandler = new MiddlewareHandler();
        $this->engineIoHandler = new EngineIOHandler($options);
        $this->eventHandler = new EventHandler(['server' => $this]);
        $this->pollingHandler = new PollingHandler($this->serverManager, $this->engineIoHandler);
        $this->httpRequestHandler = new HttpRequestHandler($this->serverManager, $this->pollingHandler, $this->engineIoHandler);
    }
    
    /**
     * 配置组件依赖和回调
     */
    private function configureComponents(): void
    {
        // 将 Logger 传递给各个组件
        $this->engineIoHandler->setLogger($this->logger);
        $this->eventHandler->setLogger($this->logger);
        
        // 设置依赖
        $this->engineIoHandler->setEventHandler($this->eventHandler);
        $this->engineIoHandler->setRoomManager($this->roomManager);
        
        // 设置回调
        $this->engineIoHandler->setSocketIOMessageHandler(fn(string $message, $connection, $session) => 
            $this->handleSocketIOMessage($message, $connection, $session)
        );
        
        $this->engineIoHandler->setBinaryMessageHandler(fn(string $binaryData, $connection, $session) => 
            $this->handleSocketIOBinaryMessage($binaryData, $connection, $session)
        );
    }
    
    /**
     * 解析监听地址并启动服务器
     */
    private function parseAndStart(string $listen, array $options): void
    {
        $this->serverManager->setConfig($options);
        
        // 移除可能的 http:// 前缀
        if (strpos($listen, 'http://') === 0) {
            $listen = substr($listen, 7);
        }
        
        // 解析地址和端口
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
    public function onMessage(TcpConnection $connection, $data): void
    {
        $this->logger->debug('Received message from client', [
            'remote_ip' => $connection->getRemoteIp(),
            'data_length' => $this->getDataLength($data),
            'data_type' => gettype($data)
        ]);
        
        $this->httpRequestHandler->handleMessage($connection, $data);
    }
    
    /**
     * 获取数据长度
     */
    private function getDataLength($data): int|string
    {
        if (is_string($data)) {
            return strlen($data);
        }
        
        if (is_object($data) && method_exists($data, '__toString')) {
            return strlen((string)$data);
        }
        
        return 'object';
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
            $packet = $this->parseSocketIOPacket($message, $session);
            if (!$packet) {
                return;
            }
            
            $namespace = $packet['namespace'] ?? '/';
            $type = $packet['type'];
            $data = $packet['data'] ?? [];
            
            $this->logger->debug('Socket.IO 收到数据包', [
                'sid' => $session->sid,
                'namespace' => $namespace,
                'type' => $type
            ]);
            
            if ($this->handleBinaryEventPlaceholder($packet, $message, $session)) {
                return;
            }
            
            $this->processPacketByType($type, $connection, $session, $namespace, $data, $packet);
        } catch (\Exception $e) {
            $this->logger->error('Socket.IO 处理消息时出错', [
                'exception' => $e->getMessage(),
                'sid' => $session->sid,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 解析Socket.IO数据包
     */
    private function parseSocketIOPacket(string $message, $session): ?array
    {
        $packet = PacketParser::parseSocketIOPacket($message) ?: null;
        if (!$packet) {
            $this->logger->warning('Socket.IO 无法解析数据包', [
                'sid' => $session->sid,
                'raw_message' => substr($message, 0, 200)
            ]);
        }
        return $packet;
    }
    
    /**
     * 处理二进制事件占位符
     */
    private function handleBinaryEventPlaceholder(array $packet, string $message, $session): bool
    {
        if ($packet['type'] === 'BINARY_EVENT' && isset($packet['binaryCount']) && $packet['binaryCount'] > 0) {
            $this->logger->debug('Socket.IO 收到二进制事件包，等待附件', [
                'binary_count' => $packet['binaryCount'],
                'sid' => $session->sid
            ]);
            
            $session->pendingBinaryPlaceholder = [
                'packet' => $message,
                'timestamp' => time()
            ];
            $session->pendingBinaryCount = $packet['binaryCount'];
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 根据包类型处理
     */
    private function processPacketByType(string $type, $connection, $session, string $namespace, $data, array $packet): void
    {
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
    }
    
    /**
     * 处理Socket.IO二进制消息
     */
    private function handleSocketIOBinaryMessage($binaryData, $connection, $session): void
    {
        $this->logger->debug('Received binary message', [
            'size' => strlen($binaryData),
            'sid' => $session->sid
        ]);
        
        if ($this->hasPendingBinaryPlaceholder($session)) {
            $this->processBinaryAttachment($binaryData, $connection, $session);
        } else {
            $this->handleStandaloneBinaryData($session);
        }
    }
    
    /**
     * 检查是否有待处理的二进制占位符
     */
    private function hasPendingBinaryPlaceholder($session): bool
    {
        return isset($session->pendingBinaryPlaceholder) && $session->pendingBinaryCount > 0;
    }
    
    /**
     * 处理二进制附件
     */
    private function processBinaryAttachment($binaryData, $connection, $session): void
    {
        $this->collectBinaryAttachment($binaryData, $session);
        
        if ($this->allAttachmentsReceived($session)) {
            $this->processCompleteBinaryEvent($session, $connection);
            $this->cleanupBinaryState($session);
        }
    }
    
    /**
     * 收集二进制附件
     */
    private function collectBinaryAttachment($binaryData, $session): void
    {
        $attachmentIndex = count($session->pendingBinaryAttachments);
        $session->pendingBinaryAttachments[$attachmentIndex] = $binaryData;
        
        $this->logger->debug('Collected binary attachment', [
            'index' => $attachmentIndex,
            'total' => $session->pendingBinaryCount,
            'sid' => $session->sid
        ]);
    }
    
    /**
     * 检查是否所有附件都已收到
     */
    private function allAttachmentsReceived($session): bool
    {
        return count($session->pendingBinaryAttachments) >= $session->pendingBinaryCount;
    }
    
    /**
     * 处理完整的二进制事件
     */
    private function processCompleteBinaryEvent($session, $connection): void
    {
        $this->logger->debug('All binary attachments received, processing complete event', [
            'sid' => $session->sid
        ]);
        
        $this->combineAndProcessBinaryAttachments($session, $connection);
    }
    
    /**
     * 清理二进制状态
     */
    private function cleanupBinaryState($session): void
    {
        $session->pendingBinaryAttachments = [];
        $session->pendingBinaryPlaceholder = null;
        $session->pendingBinaryCount = 0;
    }
    
    /**
     * 处理独立的二进制数据
     */
    private function handleStandaloneBinaryData($session): void
    {
        $this->logger->debug('No pending placeholder packet, treating as standalone attachment', [
            'sid' => $session->sid
        ]);
        // 这里可以添加独立二进制数据的处理逻辑
    }
    
    /**
     * 合并所有二进制附件并处理完整事件
     */
    private function combineAndProcessBinaryAttachments(Session $session, $connection): void
    {
        $originalPacket = $this->getOriginalPacketFromPlaceholder($session);
        if (!$originalPacket) {
            return;
        }
        
        $packet = $this->parseAndReplacePlaceholders($originalPacket, $session);
        if (!$packet) {
            return;
        }
        
        $this->processBinaryEvent($packet, $connection, $session);
    }
    
    /**
     * 从占位符中获取原始数据包
     */
    private function getOriginalPacketFromPlaceholder($session): ?string
    {
        $placeholderInfo = $session->pendingBinaryPlaceholder;
        $originalPacket = $placeholderInfo['packet'] ?? null;
        
        if (!$originalPacket) {
            $this->logger->error('No original packet found for binary placeholder', [
                'sid' => $session->sid
            ]);
        }
        
        return $originalPacket;
    }
    
    /**
     * 解析并替换二进制占位符
     */
    private function parseAndReplacePlaceholders(string $originalPacket, $session): ?array
    {
        $packet = PacketParser::parseSocketIOPacket($originalPacket);
        if (!$packet) {
            $this->logger->error('Failed to parse placeholder packet', [
                'sid' => $session->sid
            ]);
            return null;
        }
        
        $packet = PacketParser::replaceBinaryPlaceholders($packet, $session->pendingBinaryAttachments);
        return $packet;
    }
    
    /**
     * 处理二进制事件
     */
    private function processBinaryEvent(array $packet, $connection, $session): void
    {
        $this->logger->debug('Processing complete binary event', [
            'event' => $packet['event'],
            'sid' => $session->sid
        ]);
        
        $namespace = $packet['namespace'] ?? '/';
        $this->handleEventPacket(
            $connection,
            $session,
            $namespace,
            $packet['event'] ?? 'unknown',
            $this->normalizeEventData($packet['data'] ?? []),
            $packet['id'] ?? null
        );
    }
    
    /**
     * 标准化事件数据
     */
    private function normalizeEventData($data): array
    {
        if (is_array($data)) {
            return $data;
        }
        return [$data];
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
            
            $this->logger->debug('Socket.IO 处理事件', [
                'sid' => $session->sid,
                'namespace' => $namespace,
                'event' => $eventName,
                'has_ack' => $ackId !== null,
                'ack_id' => $ackId
            ]);

            $socket = $this->getOrCreateSocket($session, $namespace);
            $this->updateSessionConnection($session, $connection);
            
            $this->processEvent($session, $namespace, $eventName, $eventArgs, $socket, $ackId);
        } catch (\Exception $e) {
            $this->logger->error('Socket.IO 处理事件包时出错', [
                'exception' => $e->getMessage(),
                'sid' => $session->sid,
                'namespace' => $namespace,
                'event' => $eventName,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 获取或创建Socket实例
     */
    private function getOrCreateSocket($session, string $namespace): Socket
    {
        $sessionKey = "{$session->sid}:{$namespace}";
        return $this->sessionSocketMap[$sessionKey] ??= new Socket($session->sid, $namespace, $this);
    }
    
    /**
     * 更新会话连接信息
     */
    private function updateSessionConnection($session, $connection): void
    {
        $session->connection = $connection;
        $session->transport = 'websocket';
        $session->isWs = true;
    }
    
    /**
     * 处理事件
     */
    private function processEvent($session, string $namespace, string $eventName, array $eventArgs, $socket, ?int $ackId): void
    {
        if (!$this->eventHandler->triggerEventWithAck($session, $namespace, $eventName, $eventArgs, $ackId)) {
            $this->processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId);
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
    public function fetchSockets($namespace = '/'): array
    {
        $sockets = [];
        $activeSessions = Session::all();
        
        foreach ($activeSessions as $sid => $session) {
            if ($this->isSessionConnectedToNamespace($session, $namespace)) {
                $socket = $this->getSocketForSession($session, $namespace, $sid);
                $sockets[] = $socket;
            }
        }
        
        $this->logger->debug('fetchSockets returned socket instances', [
            'count' => count($sockets),
            'namespace' => $namespace
        ]);
        return $sockets;
    }
    
    /**
     * 检查会话是否已连接到指定命名空间
     */
    private function isSessionConnectedToNamespace($session, string $namespace): bool
    {
        return isset($session->namespaces[$namespace]) && $session->namespaces[$namespace];
    }
    
    /**
     * 获取会话对应的Socket实例
     */
    private function getSocketForSession($session, string $namespace, string $sid): Socket
    {
        $sessionKey = "{$sid}:{$namespace}";
        
        if (isset($this->sessionSocketMap[$sessionKey])) {
            return $this->sessionSocketMap[$sessionKey];
        }
        
        // 如果没有缓存，创建新的 Socket 实例
        $socket = new Socket($sid, $namespace, $this);
        $socket->session = $session;
        return $socket;
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