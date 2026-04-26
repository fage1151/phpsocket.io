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
    private static ?self $instance = null;
    
    private ?ServerManager $serverManager;
    private ?RoomManager $roomManager;
    private ?MiddlewareHandler $middlewareHandler;
    private ?EventHandler $eventHandler;
    private ?EngineIOHandler $engineIoHandler;
    private ?PollingHandler $pollingHandler;
    private ?HttpRequestHandler $httpRequestHandler;
    private array $namespaceHandlers = [];
    private ?string $sid = null;
    private ?LoggerInterface $logger = null;
    private array $sessionSocketMap = [];
    private ?string $listenAddress = null;
    private ?int $listenPort = null;
    private array $listenContext = [];
    private array $namespaces = [];
    
    /**
     * 唤醒等待的 polling 连接
     */
    public function wakePollingConnection(string $sid): void
    {
        if ($this->pollingHandler) {
            $this->pollingHandler->wakeWaitingConnection($sid);
        }
    }

    public function __construct(string $listen, array $options = [])
    {
        $this->initializeLogger($options);
        $this->initializeComponents($options);
        $this->configureComponents();
        $this->parseListenAddress($listen, $options);
        
        // 设置单例
        self::$instance = $this;
    }
    
    private function initializeLogger(array $options): void
    {
        $this->logger = new Logger($options['logLevel'] ?? $options['log_level'] ?? LogLevel::INFO);
    }
    
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
    
    private function configureComponents(): void
    {
        $this->engineIoHandler->setLogger($this->logger);
        $this->eventHandler->setLogger($this->logger);
        $this->pollingHandler->setLogger($this->logger);
        $this->httpRequestHandler->setLogger($this->logger);
        Session::setLogger($this->logger);
        $this->engineIoHandler->setEventHandler($this->eventHandler);
        $this->engineIoHandler->setRoomManager($this->roomManager);
        $this->roomManager->setServer($this);
        
        $this->engineIoHandler->setSocketIOMessageHandler(
            fn(string $message, mixed $connection, Session $session) 
                => $this->handleSocketIOMessage($message, $connection, $session)
        );
        
        $this->engineIoHandler->setBinaryMessageHandler(
            fn(string $binaryData, mixed $connection, Session $session) 
                => $this->handleSocketIOBinaryMessage($binaryData, $connection, $session)
        );
    }
    
    public function start(): void
    {
        if ($this->listenPort === null || $this->listenAddress === null) {
            throw new \RuntimeException('No listen address configured');
        }
        $this->listen($this->listenPort, $this->listenAddress, $this->listenContext);
    }
    
    private function parseListenAddress(string $listen, array $options): void
    {
        $this->serverManager->setConfig($options);
        
        if (str_starts_with($listen, 'http://')) {
            $listen = substr($listen, 7);
        }
        
        $colonPos = strpos($listen, ':');
        if ($colonPos === false) {
            throw new \Exception('Invalid listen format. Use "address:port" format.');
        }
        
        $this->listenAddress = substr($listen, 0, $colonPos);
        $portStr = substr($listen, $colonPos + 1);
        
        if (!ctype_digit($portStr)) {
            throw new \Exception('Invalid listen format. Use "address:port" format.');
        }
        
        $this->listenPort = (int)$portStr;
        $this->listenContext = ['ssl' => $options['ssl'] ?? []];
    }
    
    public function setAdapter(Adapter\AdapterInterface $adapter): void
    {
        $adapter->setLogger($this->logger);
        $this->serverManager->setAdapter($adapter);
    }
    
    public function getId(): ?string
    {
        return $this->sid;
    }
    
    public function isClusterEnabled(): bool
    {
        return $this->serverManager->isClusterEnabled();
    }

    public function on(string $event, callable $handler): self
    {
        // 默认只处理根命名空间 /
        $namespace = '/';
        if ($event === 'connection') {
            if (!isset($this->namespaceHandlers[$namespace])) {
                $this->namespaceHandlers[$namespace] = [];
            }
            $this->namespaceHandlers[$namespace]['connection'] = $handler;
        }
        
        $this->eventHandler->on($event, $handler, $namespace);
        return $this;
    }
    
    public function getSocketIoCallback(string $event, string $namespace = '/'): ?callable
    {
        if ($event === 'connection' && isset($this->namespaceHandlers[$namespace]['connection'])) {
            return $this->namespaceHandlers[$namespace]['connection'];
        }
        return null;
    }
    
    public function getNamespaceHandlers(): array
    {
        return $this->namespaceHandlers;
    }
    
    public function hasNamespaceHandler(string $namespace = '/'): bool
    {
        return isset($this->namespaceHandlers[$namespace]);
    }
    
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
    
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
    
    public function setLogLevel(string $level): void
    {
        if (method_exists($this->logger, 'setLevel')) {
            $this->logger->setLevel($level);
        }
    }

    public function onConnect(TcpConnection $connection): void
    {
        $this->logger->info('New client connection established', [
            'remote_ip' => $connection->getRemoteIp(),
            'remote_port' => $connection->getRemotePort()
        ]);
    }

    public function onMessage(TcpConnection $connection, mixed $data): void
    {
        $this->logger->debug('Received message from client', [
            'remote_ip' => $connection->getRemoteIp(),
            'data_length' => $this->getDataLength($data),
            'data_type' => gettype($data)
        ]);
        
        $this->httpRequestHandler->handleMessage($connection, $data);
    }
    
    private function getDataLength(mixed $data): int|string
    {
        if (is_string($data)) {
            return strlen($data);
        }
        
        if (is_object($data) && method_exists($data, '__toString')) {
            return strlen((string)$data);
        }
        
        return 'object';
    }

    public function onClose(TcpConnection $connection): void
    {
        if (isset($connection->session)) {
            $session = $connection->session;
            
            $this->logger->info('Client disconnecting', [
                'sid' => $session->sid
            ]);
            
            $this->roomManager->removeSession($session->sid);
            
            foreach ($session->namespaces as $namespace => $auth) {
                $this->eventHandler->triggerEvent($session, $namespace, 'disconnect', []);
            }
            
            Session::remove($session->sid);
        }
    }

    public function onError(TcpConnection $connection, $code, $msg): void
    {
        $this->logger->error('Connection error', [
            'remote_address' => $connection->getRemoteAddress(),
            'error_code' => $code,
            'error_message' => $msg
        ]);
    }

    public function listen(int $port = 8088, string $address = '0.0.0.0', array $context = []): void
    {
        $this->serverManager->validateConfigBeforeStart();
        $sslConfig = $context['ssl'] ?? [];
        if (!class_exists('\Protocols\SocketIO')) {
            class_alias('\Workerman\Protocols\Http', '\Protocols\SocketIO');
        }
        $worker = new Worker("SocketIO://{$address}:{$port}", $context);
        $worker->name = 'SocketIO-Server';
        
        $workerCount = $this->serverManager->getWorkerCount();
        if ($workerCount > 1) {
            $worker->count = $workerCount;
        }
        
        if (!empty($sslConfig)) {
            $worker->transport = 'ssl';
        }
        
        $worker->onConnect = [$this, 'onConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $worker->onError = [$this, 'onError'];
        
        $worker->onWorkerStart = function(Worker $worker) use ($address, $port, $sslConfig): void {
            $this->serverManager->initAdapter();
            $this->startHeartbeat();
            
            if ($worker->id === 0) {
                $this->logger->info('Socket.IO server started', [
                    'address' => $address,
                    'port' => $port,
                    'ssl' => !empty($sslConfig),
                    'workerCount' => $this->serverManager->getWorkerCount()
                ]);
            }
        };
    }

    private function startHeartbeat(): void
    {
        $this->engineIoHandler->startHeartbeat();
    }
    
    private function handleSocketIOMessage(string $message, mixed $connection, Session $session): void
    {
        try {
            $this->logger->debug('处理 Socket.IO 消息', [
                'sid' => $session->sid,
                'message' => substr($message, 0, 100)
            ]);
            
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
    
    private function parseSocketIOPacket(string $message, Session $session): ?array
    {
        $packet = PacketParser::parseSocketIOPacket($message);
        if (!$packet) {
            $this->logger->warning('Socket.IO 无法解析数据包', [
                'sid' => $session->sid,
                'raw_message' => substr($message, 0, 200)
            ]);
        }
        return $packet;
    }
    
    private function handleBinaryEventPlaceholder(array $packet, string $message, Session $session): bool
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
    
    private function processPacketByType(string $type, mixed $connection, Session $session, string $namespace, mixed $data, array $packet): void
    {
        // 构建 socket 信息数组，用于中间件
        $socket = [
            'id' => $session->sid,
            'namespace' => $namespace,
            'session' => $session,
            'connection' => $connection,
        ];
        
        // 构建完整的 packet 信息
        $fullPacket = [
            ...$packet,
            'namespace' => $namespace,
            'data' => $data,
            'type' => $type,
        ];
        
        // 通过 EventHandler 处理，让中间件能够执行
        // 然后回调原来的处理逻辑
        $this->eventHandler->handlePacket($fullPacket, $socket, function(array $socket, array $packet) use ($type, $connection, $session, $namespace, $data) {
            // 直接使用 match 处理，不重复触发
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
                default => null,
            };
        });
    }
    
    private function handleSocketIOBinaryMessage(string $binaryData, mixed $connection, Session $session): void
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
    
    private function hasPendingBinaryPlaceholder(Session $session): bool
    {
        return isset($session->pendingBinaryPlaceholder) && $session->pendingBinaryCount > 0;
    }
    
    private function processBinaryAttachment(string $binaryData, mixed $connection, Session $session): void
    {
        $this->collectBinaryAttachment($binaryData, $session);
        
        if ($this->allAttachmentsReceived($session)) {
            $this->processCompleteBinaryEvent($session, $connection);
            $this->cleanupBinaryState($session);
        }
    }
    
    private function collectBinaryAttachment(string $binaryData, Session $session): void
    {
        $attachmentIndex = count($session->pendingBinaryAttachments);
        $session->pendingBinaryAttachments[$attachmentIndex] = $binaryData;
        
        $this->logger->debug('Collected binary attachment', [
            'index' => $attachmentIndex,
            'total' => $session->pendingBinaryCount,
            'sid' => $session->sid
        ]);
    }
    
    private function allAttachmentsReceived(Session $session): bool
    {
        return count($session->pendingBinaryAttachments) >= $session->pendingBinaryCount;
    }
    
    private function processCompleteBinaryEvent(Session $session, mixed $connection): void
    {
        $this->logger->debug('All binary attachments received, processing complete event', [
            'sid' => $session->sid
        ]);
        
        $this->combineAndProcessBinaryAttachments($session, $connection);
    }
    
    private function cleanupBinaryState(Session $session): void
    {
        $session->pendingBinaryAttachments = [];
        $session->pendingBinaryPlaceholder = null;
        $session->pendingBinaryCount = 0;
    }
    
    private function handleStandaloneBinaryData(Session $session): void
    {
        $this->logger->debug('No pending placeholder packet, treating as standalone attachment', [
            'sid' => $session->sid
        ]);
    }
    
    private function combineAndProcessBinaryAttachments(Session $session, mixed $connection): void
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
    
    private function getOriginalPacketFromPlaceholder(Session $session): ?string
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
    
    private function parseAndReplacePlaceholders(string $originalPacket, Session $session): ?array
    {
        $packet = PacketParser::parseSocketIOPacket($originalPacket);
        if (!$packet) {
            $this->logger->error('Failed to parse placeholder packet', [
                'sid' => $session->sid
            ]);
            return null;
        }
        
        return PacketParser::replaceBinaryPlaceholders($packet, $session->pendingBinaryAttachments);
    }
    
    private function processBinaryEvent(array $packet, mixed $connection, Session $session): void
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
    
    private function normalizeEventData(mixed $data): array
    {
        return is_array($data) ? $data : [$data];
    }
    
    private function handleConnectPacket(mixed $connection, Session $session, string $namespace, mixed $authData): void
    {
        $this->logger->debug('处理连接包', [
            'sid' => $session->sid,
            'namespace' => $namespace
        ]);
        
        $session->namespaces[$namespace] = true;
        
        $sessionKey = "{$session->sid}:{$namespace}";
        $socket = $this->sessionSocketMap[$sessionKey] ??= new Socket($session->sid, $namespace, $this);
        
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
        
        $socketIoPacket = PacketParser::buildSocketIOPacket('CONNECT', [
            'namespace' => $namespace,
            'data' => [
                'sid' => $session->sid
            ]
        ]);
        
        $engineIoPacket = '4' . $socketIoPacket;
        
        $this->logger->debug('发送连接确认包', [
            'sid' => $session->sid,
            'packet' => $engineIoPacket
        ]);
        
        $result = $session->send($engineIoPacket);
        
        $this->logger->debug('连接确认包发送结果', [
            'sid' => $session->sid,
            'result' => $result
        ]);
    }
    
    private function handleDisconnectPacket(mixed $connection, Session $session, string $namespace): void
    {
        $this->logger->debug('Disconnecting namespace', [
            'namespace' => $namespace,
            'sid' => $session->sid
        ]);
        
        unset($session->namespaces[$namespace]);
        
        $this->eventHandler->triggerEvent($session, $namespace, 'disconnect', []);
    }
    
    private function handleEventPacket(mixed $connection, Session $session, string $namespace, string $eventName, array $eventArgs = [], ?int $ackId = null): void
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
    
    public function getOrCreateSocket(Session $session, string $namespace): Socket
    {
        $sessionKey = "{$session->sid}:{$namespace}";
        return $this->sessionSocketMap[$sessionKey] ??= new Socket($session->sid, $namespace, $this);
    }
    
    private function updateSessionConnection(Session $session, mixed $connection): void
    {
        $session->connection = $connection;
        $session->transport = 'websocket';
        $session->isWs = true;
    }
    
    private function processEvent(Session $session, string $namespace, string $eventName, array $eventArgs, Socket $socket, ?int $ackId): void
    {
        // 构建事件数据包信息，用于 Socket 中间件
        $packet = [
            'type' => 'EVENT',
            'namespace' => $namespace,
            'event' => $eventName,
            'data' => $eventArgs,
            'id' => $ackId
        ];

        // 执行 Socket 实例的中间件链，然后处理事件
        $socket->runMiddlewares($packet, function () use ($session, $namespace, $eventName, $eventArgs, $socket, $ackId) {
            if (!$this->eventHandler->triggerEventWithAck($session, $namespace, $eventName, $eventArgs, $ackId)) {
                $this->processEventHandlers($session, $namespace, $eventName, $eventArgs, $socket, $ackId);
            }
        });
    }
    
    private function handleAckPacket(mixed $connection, Session $session, string $namespace, mixed $data, ?int $ackId = null): void
    {
        $this->logger->debug('Received ACK packet', [
            'namespace' => $namespace,
            'ackId' => $ackId,
            'sid' => $session->sid
        ]);
        
        if ($ackId === null) {
            return;
        }
        
        if ($this->eventHandler && method_exists($this->eventHandler, 'executeAckCallback')) {
            $this->eventHandler->executeAckCallback($ackId, $data);
            return;
        }
        
        if (isset($session->ackCallbacks) && is_array($session->ackCallbacks)) {
            if (isset($session->ackCallbacks[$ackId]) && is_callable($session->ackCallbacks[$ackId])) {
                $callback = $session->ackCallbacks[$ackId];
                $callback(...$data);
                unset($session->ackCallbacks[$ackId]);
            }
        }
    }
    
    private function processEventHandlers(Session $session, string $namespace, string $eventName, array $eventArgs, Socket $socket, ?int $ackId): bool
    {
        try {
            $this->logger->debug('Processing event handlers', [
                'namespace' => $namespace,
                'eventName' => $eventName,
                'sid' => $session->sid
            ]);
            
            $foundHandler = false;
            $handler = null;
            if ($this->eventHandler->hasEventHandler($namespace, $eventName)) {
                $handler = $this->eventHandler->getEventHandler($namespace, $eventName);
                $this->logger->debug('Found EventHandler level handler', [
                    'namespace' => $namespace,
                    'eventName' => $eventName,
                    'sid' => $session->sid
                ]);
            }
            
            if ($handler) {
                // 构建ACK回调函数
                $ackCallback = null;
                if ($ackId !== null) {
                    $ackCallback = function(mixed ...$data) use ($session, $namespace, $ackId): void {
                        if (count($data) === 1) {
                            $this->eventHandler->sendAck([
                                'id' => $session->sid,
                                'session' => $session,
                                'namespace' => $namespace
                            ], $namespace, $ackId, $data[0]);
                        } else {
                            $this->eventHandler->sendAck([
                                'id' => $session->sid,
                                'session' => $session,
                                'namespace' => $namespace
                            ], $namespace, $ackId, $data);
                        }
                    };
                }
                
                // 构建事件处理器参数
                $callArgs = EventHandler::buildHandlerArguments($handler, [
                    'id' => $session->sid,
                    'session' => $session,
                    'namespace' => $namespace,
                    'socket' => $socket
                ], $eventArgs, $namespace, $ackId, $ackCallback);
                
                // 调用处理器（不再根据return值自动发送ACK）
                call_user_func_array($handler, $callArgs);
                
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
    
    public function join(string $room, ?Session $session = null): bool
    {
        return $this->roomManager->join($room, $session);
    }

    public function leave(string $room, ?Session $session = null): bool
    {
        return $this->roomManager->leave($room, $session);
    }

    public function to(mixed $roomOrSocket): Broadcaster
    {
        $broadcaster = new Broadcaster($this);
        return $broadcaster->to($roomOrSocket);
    }

    public function getRoomManager(): RoomManager
    {
        return $this->roomManager;
    }

    public function emit(string $event, mixed ...$args): self
    {
        $broadcaster = new Broadcaster($this);
        $broadcaster->emit($event, ...$args);
        return $this;
    }

    public function use(callable $middleware): self
    {
        $this->eventHandler->use($middleware);
        return $this;
    }
    
    public function getAdapter(): ?Adapter\AdapterInterface
    {
        return $this->serverManager ? $this->serverManager->getAdapter() : null;
    }
    
    public function getEventHandler(): EventHandler
    {
        return $this->eventHandler;
    }

    /**
     * 注册特定命名空间的连接事件处理器
     */
    public function registerConnectionHandlerForNamespace(string $namespace, callable $handler): void
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->namespaceHandlers[$namespace] = [];
        }
        $this->namespaceHandlers[$namespace]['connection'] = $handler;
    }
    
    public function getServerManager(): ServerManager
    {
        return $this->serverManager;
    }
    
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }
    
    public static function getInstance(): ?self
    {
        return self::$instance;
    }
    
    public function fetchSockets(string $namespace = '/'): array
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
    
    private function isSessionConnectedToNamespace(Session $session, string $namespace): bool
    {
        return isset($session->namespaces[$namespace]) && $session->namespaces[$namespace];
    }
    
    private function getSocketForSession(Session $session, string $namespace, string $sid): Socket
    {
        $sessionKey = "{$sid}:{$namespace}";
        
        if (isset($this->sessionSocketMap[$sessionKey])) {
            return $this->sessionSocketMap[$sessionKey];
        }
        
        $socket = new Socket($sid, $namespace, $this);
        $socket->session = $session;
        
        $this->sessionSocketMap[$sessionKey] = $socket;
        return $socket;
    }
    
    public function of(string $namespace = '/'): SocketNamespace
    {
        // 缓存命名空间实例
        if (!isset($this->namespaces[$namespace])) {
            $this->namespaces[$namespace] = new SocketNamespace($namespace, $this);
        }
        return $this->namespaces[$namespace];
    }
    
}
