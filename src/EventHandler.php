<?php

declare(strict_types=1);

namespace PhpSocketIO;

use ReflectionFunction;
use ReflectionException;
use Psr\Log\LoggerInterface;

/**
 * Socket.IO 事件处理器
 * 负责事件分发、命名空间管理和ACK机制
 * @package SocketIO
 */
class EventHandler
{
    private array $namespaceHandlers = []; // 命名空间处理器
    private array $connectedSockets = []; // 已连接的socket实例
    private array $middlewares = []; // 中间件队列
    private array $ackCallbacks = []; // ACK回调存储
    private array $ackCallbacksById = []; // ACK回调二级索引（按ackId）
    private ?SocketIOServer $server = null; // Socket.IO服务器实例
    private ?LoggerInterface $logger = null; // PSR-3 日志记录器

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
        $this->namespaceHandlers = [];
        $this->connectedSockets = [];
        $this->middlewares = [];
        $this->ackCallbacks = [];
        $this->ackCallbacksById = [];
        $this->server = $options['server'] ?? null;
        
        // 初始化默认命名空间处理器
        $this->initDefaultNamespace();
    }
    
    /**
     * 初始化根命名空间处理器
     */
    private function initDefaultNamespace(): void
    {
        // 根命名空间 '/' 的处理器
        $this->namespaceHandlers['/'] = [
            'connect' => null,
            'disconnect' => null,
            'events' => [],
            'sockets' => []
        ];
    }
    
    /**
     * 获取服务器实例（用于测试）
     */
    public function getServer(): ?SocketIOServer
    {
        return $this->server;
    }

    /**
     * 注册中间件
     */
    public function use(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * 执行中间件链
     */
    public function runMiddlewares(array $socket, array $packet, callable $next): mixed
    {
        $index = 0;
        $middlewares = $this->middlewares;
        
        $runner = function() use (&$index, $middlewares, $socket, $packet, $next) {
            if ($index >= count($middlewares)) {
                return $next($socket, $packet);
            }
            return $middlewares[$index++]($socket, $packet, $this->runMiddlewares(...));
        };
        
        return $runner();
    }

    /**
     * 注册命名空间处理器
     */
    public function of(string $namespace = '/', ?callable $handler = null): array
    {
        $this->namespaceHandlers[$namespace] ??= [
            'connect' => null,
            'disconnect' => null,
            'events' => [],
            'sockets' => []
        ];
        
        if ($handler) {
            $handler($this->namespaceHandlers[$namespace]);
        }
        
        return $this->namespaceHandlers[$namespace];
    }
    
    /**
     * 为事件处理器构建合适的调用参数（Socket.IO v4协议标准）
     * 严格遵循协议规范：42["event_name", "data1", "data2", ...] -> 处理器接收(data1, data2, ...)
     */
    public static function buildHandlerArguments(callable $handler, array $socket, array $eventData, string $namespace): array
    {
        try {
            $reflection = new ReflectionFunction($handler);
            $paramCount = $reflection->getNumberOfParameters();
            
            // Socket.IO v4协议标准：事件参数展开传递
            // 协议格式：42["event", "data1", "data2"] -> 处理器接收 (data1, data2)
            
            $callArgs = [];
            
            // 检查第一个参数是否为Socket实例类型
            $firstParamType = null;
            if ($paramCount > 0) {
                $params = $reflection->getParameters();
                if (isset($params[0])) {
                    $firstParam = $params[0];
                    if ($firstParam->getType() && !$firstParam->getType()->isBuiltin()) {
                        $firstParamType = $firstParam->getType()->getName();
                    }
                }
            }
            
            if ($paramCount >= 1 && self::isSocketInstanceType($firstParamType)) {
                // 处理器期望Socket实例 + 事件数据参数
                $socketInstance = self::createSocketInstanceForHandler($socket, $namespace);
                
                // Socket.IO v4标准：Socket实例后接展开的事件数据
                $callArgs = array_merge([$socketInstance], $eventData);
                
                // 使用 array_pad 替代循环补充参数
                if (count($callArgs) < $paramCount) {
                    $callArgs = array_pad($callArgs, $paramCount, null);
                }
            } else if ($paramCount === 1) {
                // 处理器期望单个参数（通常为展开的数据）
                $callArgs = $eventData;
            } else if ($paramCount >= count($eventData)) {
                // 处理器期望多个参数，且协议数据符合期望
                // 使用 array_pad 替代循环补充参数
                $callArgs = array_pad($eventData, $paramCount, null);
            } else {
                // 默认情况：按协议标准展开传递所有数据参数
                $callArgs = $eventData;
            }
            
            return $callArgs;
            
        } catch (ReflectionException $e) {
            // 备用策略：Socket实例 + 展开的事件数据（v4协议标准）
            return array_merge([$socket], $eventData);
        }
    }
    
    /**
     * 检查是否为Socket实例类型
     */
    public static function isSocketInstanceType(?string $typeName): bool
    {
        if (!$typeName) return false;
        
        // 使用 strpos 直接检查，避免循环
        if (strpos($typeName, 'Socket') !== false) {
            return true;
        }
        
        return class_exists($typeName) && method_exists($typeName, 'emit');
    }
    
    /**
     * 为事件处理器创建合适的Socket实例
     */
    public static function createSocketInstanceForHandler(array $socket, string $namespace): mixed
    {
        // 尝试使用数组中的现有Socket实例
        if (isset($socket['socket']) && is_object($socket['socket'])) {
            return $socket['socket'];
        }
        
        // 如果Session存在，尝试从中获取或构建Socket实例
        if (isset($socket['session']) && is_object($socket['session'])) {
            // 创建一个简化版的Socket实例（基于已有数据）
            return [
                'id' => $socket['id'] ?? 'unknown',
                'namespace' => $namespace,
                'session' => $socket['session']
            ];
        }
        
        // 最终回退：使用数组形式的Socket信息
        return $socket;
    }

    /**
     * 注册自定义事件处理器
     */
    public function on(string $event, callable $callback, string $namespace = '/'): void
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->of($namespace);
        }

        $this->namespaceHandlers[$namespace]['events'][$event] = $callback;
    }

    /**
     * 触发连接事件
     */
    public function triggerConnect(array $socket, string $namespace = '/', ?SocketIOServer $socketIOServer = null): void
    {
        $socket['namespace'] = $namespace;
        $this->connectedSockets[$socket['id']] = $socket;
        
        $this->logger?->info('Socket.IO 客户端连接到命名空间', [
            'sid' => $socket['id'],
            'namespace' => $namespace
        ]);
        
        // 将socket添加到命名空间
        if (isset($this->namespaceHandlers[$namespace])) {
            $this->namespaceHandlers[$namespace]['sockets'][$socket['id']] = $socket;
        }
        
        // 调用连接处理器 - 首先检查通过SocketIOServer注册的真实连接处理器
        $hasRealHandler = false;
        
        if ($socketIOServer && method_exists($socketIOServer, 'getNamespaceHandlers')) {
            // 检查是否通过SocketIOServer注册了命名空间的连接处理器
            $nsHandlers = $socketIOServer->getNamespaceHandlers();
            
            if (isset($nsHandlers[$namespace])) {
                $hasRealHandler = true;
            }
        }
        
        // 调用连接处理器 - 优先使用SocketIOServer注册的处理器
        if ($socketIOServer && $hasRealHandler && method_exists($socketIOServer, 'getSocketIoCallback')) {
            // 通过SocketIOServer注册的处理器 - 优先执行路径
            $serverManager = $socketIOServer->getServerManager();
            $roomManager = $socketIOServer->getRoomManager();
            $adapter = $serverManager ? $serverManager->getAdapter() : null;
            
            // 创建唯一的Socket类实例来注册事件监听器
            $sessionId = isset($socket['session']) ? $socket['session']->sid : null;
            $connection = $socket['connection'] ?? null;
            $realSocket = new \PhpSocketIO\Socket($sessionId, $socket['namespace'], $socketIOServer, $connection);
            
            // 集群环境下自动注册会话
            if ($serverManager && $serverManager->isClusterEnabled() && $adapter) {
                try {
                    $adapter->register($socket['id']);
                } catch (\Exception $e) {
                    $this->logger?->error('Failed to register socket in adapter', [
                        'socket_id' => $socket['id'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            
            // 调用SocketIOServer的连接处理器
            $callback = $socketIOServer->getSocketIoCallback('connection', $namespace);
            if ($callback instanceof \Closure || is_callable($callback)) {
                $callback($realSocket);
                return;
            }
        }
        
        // 备用：处理传统EventHandler中的连接处理器
        if (isset($this->namespaceHandlers[$namespace]['connect']) && 
            is_callable($this->namespaceHandlers[$namespace]['connect'])) {
            
            if ($socketIOServer) {
                // 如果已经创建过Socket实例，重用同一个实例（避免创建两个实例）
                if (!isset($realSocket)) {
                    $sessionId = isset($socket['session']) ? $socket['session']->sid : null;
                    $connection = $socket['connection'] ?? null;
                    $realSocket = new \PhpSocketIO\Socket($sessionId, $socket['namespace'], $socketIOServer, $connection);
                }
                
                call_user_func($this->namespaceHandlers[$namespace]['connect'], $realSocket);
            } else {
                call_user_func($this->namespaceHandlers[$namespace]['connect'], $socket);
            }
        }
    }

    /**
     * 触发断开连接事件
     */
    public function triggerDisconnect(array $socket, string $reason = 'client disconnect'): void
    {
        $namespace = $socket['namespace'] ?? '/';
        $socketId = $socket['id'] ?? '';
        
        $this->logger?->info('Socket.IO 客户端断开连接', [
            'sid' => $socketId,
            'namespace' => $namespace,
            'reason' => $reason
        ]);
        
        // 从命名空间移除socket
        if (isset($this->namespaceHandlers[$namespace])) {
            unset($this->namespaceHandlers[$namespace]['sockets'][$socketId]);
        }
        
        // 从全局连接列表移除
        unset($this->connectedSockets[$socketId]);
        
        // 集群环境下注销会话
        $adapter = null;
        
        // 方式1：从EventHandler本身的server实例获取适配器
        if ($this->server && method_exists($this->server, 'getServerManager')) {
            $serverManager = $this->server->getServerManager();
            if ($serverManager && $serverManager->isClusterEnabled()) {
                $adapter = $serverManager->getAdapter();
            }
        }
        
        // 方式2：从Socket实例直接获取适配器（如果存在对应的Socket对象）
        if (!$adapter && isset($socket['socket']) && method_exists($socket['socket'], 'getServerManager')) {
            $socketInstance = $socket['socket'];
            $serverManager = $socketInstance->getServerManager();
            if ($serverManager && $serverManager->isClusterEnabled()) {
                $adapter = $serverManager->getAdapter();
            }
        }
        
        // 执行会话注销
        if ($adapter && method_exists($adapter, 'unregister')) {
            try {
                $adapter->unregister($socketId);
            } catch (\Exception $e) {
                $this->logger?->error('Failed to unregister socket in adapter', [
                    'socket_id' => $socketId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        // 调用断开连接处理器（Socket.IO v4协议标准）
        if (isset($this->namespaceHandlers[$namespace]['disconnect']) && 
            is_callable($this->namespaceHandlers[$namespace]['disconnect'])) {
            call_user_func($this->namespaceHandlers[$namespace]['disconnect'], $socket, $reason);
        }
    }

    /**
     * 处理Socket.IO事件包
     */
    public function handlePacket(array $packet, array $socket): mixed
    {
        // 执行中间件链
        return $this->runMiddlewares($socket, $packet, function(array $socket, array $packet) {
            switch ($packet['type']) {
                case 'CONNECT':
                    return $this->handleConnect($packet, $socket);
                case 'DISCONNECT':
                    return $this->handleDisconnect($packet, $socket);
                case 'EVENT':
                case 'BINARY_EVENT':
                    return $this->handleEvent($packet, $socket);
                case 'ACK':
                case 'BINARY_ACK':
                    return $this->handleAck($packet, $socket);
                case 'CONNECT_ERROR':
                    return $this->handleError($packet, $socket);
                default:
                    $this->sendError($socket, "Unknown packet type: {$packet['type']}");
                    return false;
            }
        });
    }

    /**
     * 处理连接请求
     */
    private function handleConnect(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $auth = $packet['auth'] ?? null;
        
        // 验证授权信息
        if (!$this->validateAuth($namespace, $auth)) {
            $this->sendError($socket, 'Authentication failed');
            return false;
        }
        
        $this->triggerConnect($socket, $namespace);
        
        // 发送连接确认
        $this->sendPacket($socket, [
            'type' => 'CONNECT',
            'namespace' => $namespace,
            'data' => ['sid' => $socket['id']]
        ]);
        
        return true;
    }

    /**
     * 处理断开连接
     */
    private function handleDisconnect(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $this->triggerDisconnect($socket, 'client disconnect');
        return true;
    }

    /**
     * 处理事件（严格遵循Socket.IO v4协议标准）
     */
    private function handleEvent(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $eventName = $packet['event'] ?? '';
        $eventData = $packet['data'] ?? [];
        $ackId = $packet['id'] ?? null;
        
        // Socket.IO v4协议验证
        if (!$eventName) {
            $this->sendError($socket, 'Event name is required');
            return false;
        }
        
        // 检查并执行EventHandler级别处理器
        if ($this->hasEventHandler($namespace, $eventName)) {
            $handler = $this->namespaceHandlers[$namespace]['events'][$eventName];
            // 构建事件处理器参数
            $callArgs = self::buildHandlerArguments($handler, $socket, $eventData, $namespace);
            // 处理ACK事件
            if ($ackId !== null) {
                $result = call_user_func_array($handler, $callArgs);
                
                if ($result !== null) {
                    $this->sendAck($socket, $namespace, $ackId, $result);
                }
                
                return (bool) $result;
            } else {
                // 非ACK事件：直接调用处理器
                call_user_func_array($handler, $callArgs);
                return true;
            }
        }
        
        return false;
    }

    /**
     * 处理ACK确认包（Socket.IO v4协议标准）
     * 严格遵循协议：ACK回调接收展开的参数，与EVENT参数格式完全一致
     */
    private function handleAck(array $packet, array $socket): bool
    {
        $namespace = $packet['namespace'] ?? '/';
        $ackId = $packet['id'] ?? null;
        $ackData = $packet['data'] ?? [];
        
        if ($ackId === null) {
            $this->sendError($socket, 'ACK ID is required');
            return false;
        }
        
        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";
        
        if (isset($this->ackCallbacks[$callbackKey])) {
            $callback = $this->ackCallbacks[$callbackKey];
            
            // Socket.IO v4协议标准：ACK回调接收展开的参数，确保数据格式完全一致
            try {
                $reflection = new ReflectionFunction($callback);
                $expectedParams = $reflection->getNumberOfParameters();
                
                // 构建ACK调用参数：展开数据，确保协议一致性
                $ackArgs = [];
                if (is_array($ackData)) {
                    // 数组数据：按协议展开传递
                    if ($expectedParams >= count($ackData)) {
                        $ackArgs = array_pad($ackData, $expectedParams, null);
                    } else {
                        $ackArgs = $ackData;
                    }
                } else {
                    // 单个数据：按协议处理
                    $ackArgs = array_pad([$ackData], $expectedParams, null);
                }
                
                // 执行ACK回调
                call_user_func_array($callback, $ackArgs);
                
                // 清理已使用的ACK回调
                $this->removeAckCallback($callbackKey, $ackId);
                
                return true;
                
            } catch (ReflectionException $e) {
                $this->logger?->debug('Reflection failed for ACK callback, using fallback strategy', [
                    'ack_id' => $ackId,
                    'error' => $e->getMessage()
                ]);
                // 备用策略：直接传递ACK数据
                call_user_func($callback, $ackData);
                $this->removeAckCallback($callbackKey, $ackId);
                return true;
            }
        }
        
        $this->sendError($socket, "ACK callback not found for id: {$ackId}");
        return false;
    }

    /**
     * 处理错误包
     */
    private function handleError(array $packet, array $socket): bool
    {
        return false;
    }

    /**
     * 验证授权信息
     */
    private function validateAuth(string $namespace, mixed $auth): bool
    {
        // 简单的验证逻辑，可根据需求扩展
        if (($namespace === '/' || $namespace === '/chat') && $auth === null) {
            return true;
        }
        
        // 其他命名空间的认证逻辑
        return is_array($auth) && isset($auth['token']) && !empty($auth['token']);
    }

    /**
     * 发送数据包
     */
    private function sendPacket(array $socket, array $packet): void
    {
        // 检查socket中是否有session对象
        if (isset($socket['session']) && method_exists($socket['session'], 'send')) {
            $session = $socket['session'];
            
            // 根据数据包类型构建不同的格式
            switch ($packet['type']) {
                case 'ACK':
                    // 构建ACK响应包
                    $ackId = $packet['id'] ?? null;
                    $ackData = $packet['data'] ?? [];
                    
                    // Socket.IO v4 ACK格式: 43[ackId, data1, data2, ...]
                    $ackPacket = json_encode(array_merge([$ackId], $ackData));
                    $engineIOPacket = '43' . $ackPacket;
                    
                    $session->send($engineIOPacket);
                    break;
                    
                case 'CONNECT':
                    // 构建连接确认包
                    $namespace = $packet['namespace'] ?? '/';
                    if ($namespace !== '/') {
                        $connectPacket = '40' . $namespace . ',';
                    } else {
                        $connectPacket = '40';
                    }
                    $session->send($connectPacket);
                    break;
                    
                case 'EVENT':
                    // 构建事件包
                    $eventName = $packet['event'] ?? '';
                    $eventData = $packet['data'] ?? [];
                    
                    $eventPacket = json_encode(array_merge([$eventName], $eventData));
                    $engineIOPacket = '42' . $eventPacket;
                    
                    $session->send($engineIOPacket);
                    break;
                    
                case 'CONNECT_ERROR':
                    // 构建错误包
                    $error = $packet['error'] ?? 'Unknown error';
                    $errorPacket = json_encode(['error' => $error]);
                    $engineIOPacket = '44' . $errorPacket;
                    
                    $session->send($engineIOPacket);
                    break;
                    
                default:
                    // 默认处理
                    $defaultPacket = json_encode($packet);
                    $engineIOPacket = '42' . $defaultPacket;
                    $session->send($engineIOPacket);
                    break;
            }
        }
    }

    /**
     * 发送错误
     */
    private function sendError(array $socket, string $message): void
    {
        $this->sendPacket($socket, [
            'type' => 'CONNECT_ERROR',
            'namespace' => $socket['namespace'] ?? '/',
            'error' => $message
        ]);
    }

    /**
     * 发送ACK确认
     */
    private function sendAck(array $socket, string $namespace, int $ackId, mixed $data): void
    {
        // 直接构建并发送ACK数据包
        if (isset($socket['session']) && method_exists($socket['session'], 'send')) {
            $session = $socket['session'];
            
            // 构建ACK数据
            $ackData = [$data];
            $ackPacket = json_encode($ackData);
            
            // 构建Engine.IO数据包
            $engineIOPacket = $this->buildAckEngineIOPacket($namespace, $ackId, $ackPacket);
            
            // 发送数据包
            $this->sendWebSocketMessage($session, $engineIOPacket);
        } else {
            // 回退到sendPacket方法
            $ackData = is_array($data) ? $data : [$data];
            $this->sendPacket($socket, [
                'type' => 'ACK',
                'namespace' => $namespace,
                'data' => $ackData,
                'id' => $ackId
            ]);
        }
    }
    
    /**
     * 构建ACK的Engine.IO数据包
     */
    private function buildAckEngineIOPacket(string $namespace, int $ackId, string $ackPacket): string
    {
        if ($namespace !== '/') {
            return '43' . $namespace . ',' . $ackId . $ackPacket;
        } else {
            return '43' . $ackId . $ackPacket;
        }
    }
    
    /**
     * 发送WebSocket消息
     */
    private function sendWebSocketMessage($session, string $message): void
    {
        if (!method_exists($session, 'send')) {
            return;
        }
        
        try {
            $session->send($message);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send WebSocket message', [
                'sid' => $session->sid ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 存储ACK回调函数
     */
    public function storeAckCallback(array $socket, string $namespace, int $ackId, callable $callback): void
    {
        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";
        $this->ackCallbacks[$callbackKey] = $callback;
        
        // 添加二级索引
        if (!isset($this->ackCallbacksById[$ackId])) {
            $this->ackCallbacksById[$ackId] = [];
        }
        $this->ackCallbacksById[$ackId][] = $callbackKey;
    }
    
    /**
     * 移除ACK回调（同时清理二级索引）
     */
    private function removeAckCallback(string $callbackKey, int $ackId): void
    {
        // 从主存储移除
        unset($this->ackCallbacks[$callbackKey]);
        
        // 从二级索引移除
        if (isset($this->ackCallbacksById[$ackId])) {
            $index = array_search($callbackKey, $this->ackCallbacksById[$ackId], true);
            if ($index !== false) {
                array_splice($this->ackCallbacksById[$ackId], $index, 1);
                if (empty($this->ackCallbacksById[$ackId])) {
                    unset($this->ackCallbacksById[$ackId]);
                }
            }
        }
    }
    
    /**
     * 执行ACK回调函数（优化版，使用二级索引）
     */
    public function executeAckCallback(int $ackId, mixed $data): bool
    {
        // 使用二级索引直接查找，避免遍历所有回调
        if (!isset($this->ackCallbacksById[$ackId])) {
            return false;
        }
        
        foreach ($this->ackCallbacksById[$ackId] as $callbackKey) {
            if (!isset($this->ackCallbacks[$callbackKey])) {
                continue;
            }
            
            $callback = $this->ackCallbacks[$callbackKey];
            $ackArgs = is_array($data) ? $data : [$data];
            
            try {
                $reflection = new ReflectionFunction($callback);
                $expectedParams = $reflection->getNumberOfParameters();
                
                // 构建ACK调用参数
                $finalArgs = [];
                if (is_array($data)) {
                    if ($expectedParams >= count($data)) {
                        $finalArgs = array_pad($data, $expectedParams, null);
                    } else {
                        $finalArgs = $data;
                    }
                } else {
                    $finalArgs = array_pad([$data], $expectedParams, null);
                }
                
                call_user_func_array($callback, $finalArgs);
                $this->removeAckCallback($callbackKey, $ackId);
                return true;
            } catch (\Exception $e) {
                $this->logger?->error('Failed to execute ACK callback', [
                    'ack_id' => $ackId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * 规范化命名空间
     */
    private function normalizeNamespace(string $namespace): string
    {
        return $namespace === '' ? '/' : $namespace;
    }
    
    /**
     * 检查是否存在事件处理器
     */
    public function hasEventHandler(string $namespace, string $eventName): bool
    {
        $namespace = $this->normalizeNamespace($namespace);
        return isset($this->namespaceHandlers[$namespace]['events'][$eventName]);
    }
    
    /**
     * 获取事件处理器
     */
    public function getEventHandler(string $namespace, string $eventName): ?callable
    {
        $namespace = $this->normalizeNamespace($namespace);
        return $this->namespaceHandlers[$namespace]['events'][$eventName] ?? null;
    }

    /**
     * 触发事件处理（兼容方法）
     */
    public function triggerEvent(Session $session, string $namespace = '/', string $eventName = '', array $args = []): mixed
    {
        return $this->triggerEventWithAck($session, $namespace, $eventName, $args, null);
    }
    
    /**
     * 触发带ACK的事件处理
     */
    public function triggerEventWithAck(Session $session, string $namespace = '/', string $eventName = '', array $args = [], ?int $ackId = null): bool
    {
        $socket = [
            'id' => $session->getSid(),
            'session' => $session,
            'namespace' => $namespace
        ];
        
        switch ($eventName) {
            case 'connection':
                $this->triggerConnect($socket, $namespace);
                return true;
            case 'disconnect':
                $this->triggerDisconnect($socket, 'client disconnect');
                return true;
        };
        
        if ($eventName) {
            $packet = [
                'type' => 'EVENT',
                'namespace' => $namespace,
                'event' => $eventName,
                'data' => $args
            ];
            
            if ($ackId !== null) {
                $packet['id'] = $ackId;
            }
            
            return $this->handlePacket($packet, $socket);
        }
        
        return false;
    }
    
    /**
     * 分发事件（兼容方法）
     */
    public function dispatchEvent(Session $session, string $eventName, mixed $eventData, array $socket): bool
    {
        return $this->triggerEvent($session, $socket['namespace'] ?? '/', $eventName, $eventData);
    }
}
