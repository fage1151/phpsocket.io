<?php

namespace PhpSocketIO;

use ReflectionFunction;
use ReflectionException;

/**
 * Socket.IO 事件处理器
 * 负责事件分发、命名空间管理和ACK机制
 * @package SocketIO
 */
class EventHandler
{
    private $namespaceHandlers = []; // 命名空间处理器
    private $connectedSockets  = []; // 已连接的socket实例
    private $middlewares       = []; // 中间件队列
    private $ackCallbacks      = []; // ACK回调存储
    private $server            = null; // Socket.IO服务器实例

    /**
     * 构造函数
     */
    public function __construct(array $options = [])
    {
        $this->namespaceHandlers = [];
        $this->connectedSockets = [];
        $this->middlewares = [];
        $this->ackCallbacks = [];
        $this->server = $options['server'] ?? null;
        
        // 初始化默认命名空间处理器
        $this->initDefaultNamespace();
    }

    /**
     * 初始化根命名空间处理器
     */
    private function initDefaultNamespace()
    {
        // 根命名空间 '/' 的处理器
        $this->namespaceHandlers['/'] = [
            'connect' => null,    // 连接回调
            'disconnect' => null, // 断开连接回调
            'events' => [],       // 事件处理器
            'sockets' => []       // 连接的socket实例
        ];
    }
    
    /**
     * 获取服务器实例（用于测试）
     * @return mixed|null
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * 注册中间件
     * @param callable $middleware 中间件函数
     */
    public function use(callable $middleware)
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
        
        $runner = fn() => $index >= count($middlewares) 
            ? $next($socket, $packet) 
            : $middlewares[$index++]($socket, $packet, $runner);
        
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
     * Socket.IO v4服务端消息发送API - 单发
     */
    public function emitToSocket($socketId, $event, $data)
    {
        $packet = PacketParser::buildSocketIOPacket('EVENT', [
            'event' => $event,
            'data' => $data
        ]);
        
        // 查找socket实例并触发事件
        foreach ($this->connectedSockets as $socket) {
            if ($socket['id'] === $socketId) {
                return $this->handleEvent([
                    'type' => 'EVENT',
                    'event' => $event,
                    'data' => $data,
                    'args' => is_array($data) ? $data : [$data]
                ], $socket);
            }
        }
        return false;
    }

    /**
     * Socket.IO v4服务端消息发送API - 群发
     */
    public function emitToRoom($room, $event, $data)
    {
        // 实际实现需要通过RoomManager获取房间成员
        // 这里提供伪代码实现逻辑
        $successCount = 0;
        
        // foreach ($roomManager->getRoomMembers($room) as $socketId) {
        //     $this->emitToSocket($socketId, $event, $data);
        //     $successCount++;
        // }
        
        return $successCount;
    }

    /**
     * Socket.IO v4服务端消息发送API - 广播
     */
    public function broadcast($event, $data, $excludeSockets = [])
    {
        $successCount = 0;
        
        foreach ($this->connectedSockets as $socket) {
            if (!in_array($socket['id'], $excludeSockets)) {
                $this->emitToSocket($socket['id'], $event, $data);
                $successCount++;
            }
        }
        
        return $successCount;
    }
    
    
    /**
     * 为事件处理器构建合适的调用参数（Socket.IO v4协议标准）
     * 严格遵循协议规范：42["event_name", "data1", "data2", ...] -> 处理器接收(data1, data2, ...)
     */
    private static function buildHandlerArguments(callable $handler, array $socket, array $eventData, string $namespace): array
    {
        try {
            $reflection = new ReflectionFunction($handler);
            $paramCount = $reflection->getNumberOfParameters();
            
            echo "[socketio_v4_protocol] 事件处理器参数分析: 期望参数={$paramCount}, 协议数据=" . json_encode($eventData) . "\n";
            
            // Socket.IO v4协议标准：事件参数展开传递
            // 协议格式：42["event", "data1", "data2"] => 处理器接收 (data1, data2)
            
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
                echo "[socketio_v4_protocol] 使用Socket实例类型参数，创建实例: " . gettype($socketInstance) . "\n";
                
                // Socket.IO v4标准：Socket实例后接展开的事件数据
                $callArgs = array_merge([$socketInstance], $eventData);
                
                // 参数数量调整以确保调用成功
                if (count($callArgs) < $paramCount) {
                    for ($i = count($callArgs); $i < $paramCount; $i++) {
                        $callArgs[] = null;
                    }
                } else if (count($callArgs) > $paramCount) {
                    // 协议标准允许保留额外参数，超出的参数将被忽略但保持传输完整
                    echo "[socketio_v4_protocol] 注意: 事件数据参数多于处理器期望参数，遵循协议标准保留数据完整性\n";
                }
            } else if ($paramCount === 1) {
                // 处理器期望单个参数（通常为展开的数据）
                if (count($eventData) === 1) {
                    // 单参数：直接传递
                    $callArgs = $eventData;
                } else {
                    // 多参数：按Socket.IO v4标准展开传递
                    $callArgs = $eventData;
                    echo "[socketio_v4_protocol] 单参数处理器接收多参数数据，按协议标准传递所有数据\n";
                }
            } else if ($paramCount >= count($eventData)) {
                // 处理器期望多个参数，且协议数据符合期望
                $callArgs = $eventData;
                // 补充可能的可选参数
                while (count($callArgs) < $paramCount) {
                    $callArgs[] = null;
                }
            } else {
                // 默认情况：按协议标准展开传递所有数据参数
                $callArgs = $eventData;
                echo "[socketio_v4_protocol] 使用默认协议标准：展开传递所有事件数据参数\n";
            }
            
            echo "[socketio_v4_protocol] 最终构建的调用参数: " . json_encode($callArgs) . "\n";
            return $callArgs;
            
        } catch (ReflectionException $e) {
            echo "[socketio_v4_protocol] 反射分析失败，使用Socket.IO v4默认策略: " . $e->getMessage() . "\n";
            // 备用策略：Socket实例 + 展开的事件数据（v4协议标准）
            return array_merge([$socket], $eventData);
        }
    }
    
    /**
     * 检查是否为Socket实例类型
     */
    private static function isSocketInstanceType(?string $typeName): bool
    {
        if (!$typeName) return false;
        
        // 常见的Socket实例类名模式
        $socketClasses = ['Socket', 'ClientSocket', 'SocketInstance', 'PhpSocketIO\\ClientSocket'];
        foreach ($socketClasses as $socketClass) {
            if (strpos($typeName, $socketClass) !== false || $typeName === $socketClass) {
                return true;
            }
        }
        
        return class_exists($typeName) && method_exists($typeName, 'emit');
    }
    
    /**
     * 为事件处理器创建合适的Socket实例
     */
    private static function createSocketInstanceForHandler(array $socket, string $namespace) 
    {
        // 尝试使用数组中的现有Socket实例
        if (isset($socket['socket']) && is_object($socket['socket'])) {
            echo "[debug] 使用现有的Socket对象实例\n";
            return $socket['socket'];
        }
        
        // 如果Session存在，尝试从中获取或构建Socket实例
        if (isset($socket['session']) && is_object($socket['session'])) {
            try {
                echo "[debug] 通过Session关联Socket\n";
                // 创建一个简化版的Socket实例（基于已有数据）
                // 这里应该实现更完整的Socket实例创建逻辑，但为了简化，先使用数组形式
                return [
                    'id' => $socket['id'] ?? 'unknown',
                    'namespace' => $namespace,
                    'session' => $socket['session']
                ];
            } catch (Exception $e) {
                echo "[debug] Session关联失败: " . $e->getMessage() . "\n";
            }
        }
        
        // 最终回退：使用数组形式的Socket信息
        echo "[debug] 使用数组形式的Socket信息作为实例\n";
        return $socket;
    }

    /**
     * 完成连接事件处理
     */
    private function finalizeConnection(array $socket, string $namespace)
    {
        echo "[event] 连接事件处理完成\n";
        return true;
    }

    /**
     * 注册连接事件处理器
     * @param callable $callback 连接回调
     * @param string $namespace 命名空间
     */
    public function onConnect(callable $callback, $namespace = '/')
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->initNamespaceHandler($namespace);
        }
        
        $this->namespaceHandlers[$namespace]['connect'] = $callback;
    }

    /**
     * 注册断开连接事件处理器
     * @param callable $callback 断开连接回调
     * @param string $namespace 命名空间
     */
    public function onDisconnect(callable $callback, $namespace = '/')
    {
        if (!isset($this->namespaceHandlers[$namespace])) {
            $this->of($namespace);
        }
        
        $this->namespaceHandlers[$namespace]['disconnect'] = $callback;
    }

    /**
     * 注册自定义事件处理器
     * @param string $event 事件名称
     * @param callable $callback 事件回调
     * @param string $namespace 命名空间
     */
    public function on($event, callable $callback, $namespace = '/')
    {
        echo "[eventhandler] 开始注册事件: {$namespace}::{$event}\n";
        echo "[debug] 事件类型: " . (strpos($event, ' ') !== false ? "复合事件" : "单一事件") . "\n";

        if (!isset($this->namespaceHandlers[$namespace])) {
            echo "[debug] 命名空间{$namespace}不存在，创建新的命名空间处理器\n";
            $this->of($namespace);
        }

        // 检查是否已存在同名事件处理器
        $existingEvents = array_keys($this->namespaceHandlers[$namespace]['events'] ?? []);
        if (in_array($event, $existingEvents)) {
            echo "[debug] 警告: 事件{$namespace}::{$event}已存在，将被覆盖\n";
        }

        $this->namespaceHandlers[$namespace]['events'][$event] = $callback;
        
        echo "[eventhandler] 成功注册事件: {$namespace}::{$event}\n";
        echo "[debug] 当前命名空间事件数量: " . count($this->namespaceHandlers[$namespace]['events']) . "\n";
        echo "[debug] 所有事件: " . implode(', ', array_keys($this->namespaceHandlers[$namespace]['events'])) . "\n";
    }

    /**
     * 触发连接事件
     * @param array $socket socket实例
     * @param string $namespace 命名空间
     * @param mixed $socketIOServer SocketIO服务器实例
     */
    public function triggerConnect(array $socket, $namespace = '/', $socketIOServer = null)
    {
        echo "[eventhandler] triggerConnect调用开始: namespace={$namespace}, socketID={$socket['id']}\n";
        
        $socket['namespace'] = $namespace;
        $this->connectedSockets[$socket['id']] = $socket;
        
        // 将socket添加到命名空间
        if (isset($this->namespaceHandlers[$namespace])) {
            $this->namespaceHandlers[$namespace]['sockets'][$socket['id']] = $socket;
        }
        
    // 调用连接处理器 - 首先检查通过SocketIOServer注册的真实连接处理器
        $hasRealHandler = false;
        echo "[eventhandler] 检查SocketIOServer连接处理器...\n";
        
        if ($socketIOServer && method_exists($socketIOServer, 'getNamespaceHandlers')) {
            // 检查是否通过SocketIOServer注册了命名空间的连接处理器
            $nsHandlers = $socketIOServer->getNamespaceHandlers();
            echo "[eventhandler] 命名空间处理器列表: " . json_encode(array_keys($nsHandlers)) . "\n";
            
            if (isset($nsHandlers[$namespace])) {
                $hasRealHandler = true;
                echo "[eventhandler] 找到命名空间 '{$namespace}' 的连接处理器\n";
            } else {
                echo "[eventhandler] 未找到命名空间 '{$namespace}' 的连接处理器\n";
            }
        } else {
            echo "[eventhandler] SocketIOServer实例未提供或缺少getNamespaceHandlers方法\n";
        }
        
        // 调用连接处理器 - 优先使用SocketIOServer注册的处理器
        if ($socketIOServer && $hasRealHandler && method_exists($socketIOServer, 'getSocketIoCallback')) {
            // 通过SocketIOServer注册的处理器 - 优先执行路径
            $serverManager = $socketIOServer->getServerManager();
            $roomManager = new \PhpSocketIO\RoomManager();
            $adapter = $serverManager ? $serverManager->getAdapter() : null;
            
            // 创建唯一的Socket类实例来注册事件监听器
            $realSocket = new \PhpSocketIO\Socket($socket['session']->sid, $socket['namespace'], $socketIOServer, $socket['connection']);
            
            // 集群环境下自动注册会话
            if ($serverManager && $serverManager->isClusterEnabled() && $adapter) {
                try {
                    $adapter->registerSession($socket['id']);
                    echo "[cluster] session registered for sid={$socket['id']}\n";
                } catch (\Exception $e) {
                    echo "[cluster] session registration failed: " . $e->getMessage() . "\n";
                }
            }
            
            // 调用SocketIOServer的连接处理器
            $callback = $socketIOServer->getSocketIoCallback('connection', $namespace);
            if ($callback instanceof \Closure || is_callable($callback)) {
                echo "[eventhandler] 调用SocketIOServer连接处理器，传递真实Socket实例\n";
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
                    $realSocket = new \PhpSocketIO\Socket($socket['session']->sid, $socket['namespace'], $socketIOServer, $socket['connection']);
                }
                
                echo "[eventhandler] 调用传统EventHandler连接处理器，传递真实Socket实例\n";
                call_user_func($this->namespaceHandlers[$namespace]['connect'], $realSocket);
            } else {
                echo "[eventhandler] 调用连接处理器，传递数组格式socket\n";
                call_user_func($this->namespaceHandlers[$namespace]['connect'], $socket);
            }
        }
        // 对于根命名空间'/'，server.php中的connection事件处理器会在Socket创建后触发
        // 此处无需警告，这是正常的处理流程
    }

    /**
     * 触发断开连接事件
     * @param array $socket socket实例
     * @param string $reason 断开原因
     */
    public function triggerDisconnect(array $socket, $reason = 'client disconnect')
    {
        $namespace = $socket['namespace'] ?? '/';
        $socketId = $socket['id'] ?? '';
        
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
        if (!$adapter && isset($socket['socket']) && $socket['socket'] instanceof \PhpSocketIO\ClientSocket) {
            $socketInstance = $socket['socket'];
            $serverManager = $socketInstance->getServerManager();
            if ($serverManager && $serverManager->isClusterEnabled()) {
                $adapter = $serverManager->getAdapter();
            }
        }
        
        // 执行会话注销
        if ($adapter && method_exists($adapter, 'unregisterSession')) {
            try {
                $adapter->unregisterSession($socketId);
                echo "[cluster] session unregistered for sid={$socketId}\n";
            } catch (\Exception $e) {
                echo "[cluster] session unregistration failed: " . $e->getMessage() . "\n";
            }
        }
        
        // 调用断开连接处理器（Socket.IO v4协议标准）
        if (isset($this->namespaceHandlers[$namespace]['disconnect']) && 
            is_callable($this->namespaceHandlers[$namespace]['disconnect'])) {
            
            echo "[socketio_v4_protocol] 触发断开连接事件: namespace={$namespace}, reason={$reason}\n";
            call_user_func($this->namespaceHandlers[$namespace]['disconnect'], $socket, $reason);
        }
    }

    /**
     * 处理Socket.IO事件包
     * @param array $packet 数据包
     * @param array $socket socket实例
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
     * @param array $packet 数据包
     * @param array $socket socket实例
     */
    private function handleConnect(array $packet, array $socket)
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
     * @param array $packet 数据包
     * @param array $socket socket实例
     */
    private function handleDisconnect(array $packet, array $socket)
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
     * 规范化调试数据，防止循环引用或过大数据
     */
    private function normalizeDebugData($data) {
        if (is_array($data)) {
            $normalized = [];
            foreach ($data as $key => $value) {
                if (is_object($value)) {
                    $normalized[$key] = '[object: ' . get_class($value) . ']';
                } else if (is_array($value)) {
                    $normalized[$key] = '[array: ' . count($value) . ' elements]';
                } else if (is_string($value) && strlen($value) > 100) {
                    $normalized[$key] = substr($value, 0, 100) . '...';
                } else {
                    $normalized[$key] = $value;
                }
            }
            return $normalized;
        }
        return $data;
    }

    /**
     * 处理ACK确认包（Socket.IO v4协议标准）
     * 严格遵循协议：ACK回调接收展开的参数，与EVENT参数格式完全一致
     * @param array $packet 数据包
     * @param array $socket socket实例
     */
    private function handleAck(array $packet, array $socket)
    {
        $namespace = $packet['namespace'] ?? '/';
        $ackId = $packet['id'] ?? null;
        $ackData = $packet['data'] ?? [];
        
        echo "[socketio_v4_protocol] 处理ACK包: ACK_ID={$ackId}, namespace={$namespace}, data_count=" . (is_array($ackData) ? count($ackData) : 1) . "\n";
        
        if ($ackId === null) {
            echo "[socketio_v4_protocol_error] ACK ID为空，不符合协议标准\n";
            $this->sendError($socket, 'ACK ID is required');
            return false;
        }
        
        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";
        
        if (isset($this->ackCallbacks[$callbackKey])) {
            $callback = $this->ackCallbacks[$callbackKey];
            
            // Socket.IO v4协议标准：ACK回调接收展开的参数，确保数据格式完全一致
            // 协议标准：42["event", "data1", "data2"] -> ACK事件：["result1", "result2"] -> 回调接收(result1, result2)
            
            try {
                $reflection = new ReflectionFunction($callback);
                $expectedParams = $reflection->getNumberOfParameters();
                
                // 构建ACK调用参数：展开数据，确保协议一致性
                $ackArgs = [];
                if (is_array($ackData)) {
                    // 数组数据：按协议展开传递
                    if ($expectedParams >= count($ackData)) {
                        $ackArgs = $ackData;
                        // 补充可选参数
                        while (count($ackArgs) < $expectedParams) {
                            $ackArgs[] = null;
                        }
                    } else {
                        // 参数过多：按协议标准传递所有数据（超出的参数将被忽略）
                        $ackArgs = $ackData;
                        echo "[socketio_v4_protocol] ACK数据参数多于回调预期，按协议标准传递完整数据\n";
                    }
                } else {
                    // 单个数据：按协议处理
                    $ackArgs = [$ackData];
                    // 补充可选参数
                    while (count($ackArgs) < $expectedParams) {
                        $ackArgs[] = null;
                    }
                }
                
                echo "[socketio_v4_protocol] ACK回调调用: ACK_ID={$ackId}, 参数个数=" . count($ackArgs) . ", 数据=" . json_encode($this->normalizeDebugData($ackArgs)) . "\n";
                
                // 执行ACK回调（严格按照协议展开的参数格式）
                call_user_func_array($callback, $ackArgs);
                
                // 清理已使用的ACK回调
                unset($this->ackCallbacks[$callbackKey]);
                echo "[socketio_v4_protocol] ACK回调执行成功，已清理回调存储: ACK_ID={$ackId}\n";
                
                return true;
                
            } catch (ReflectionException $e) {
                echo "[socketio_v4_protocol_error] ACK回调反射分析失败: " . $e->getMessage() . "\n";
                // 备用策略：直接传递ACK数据
                call_user_func($callback, $ackData);
                unset($this->ackCallbacks[$callbackKey]);
                return true;
            }
        }
        
        // Socket.IO v4协议标准：ACK回调未找到时发送标准CONNECT_ERROR包
        echo "[socketio_v4_protocol_error] 未找到ACK回调: ACK_ID={$ackId}, callback_key={$callbackKey}\n";
        $this->sendError($socket, "ACK callback not found for id: {$ackId}");
        return false;
    }


    
    /**
     * 解码二进制数据占位符（Socket.IO v4协议标准）
     * 将协议中的二进制占位符转换为实际二进制数据
     */
    private function decodeBinaryData(array $packet, array $socket)
    {
        if (!isset($packet['data']) || !is_array($packet['data'])) {
            return [];
        }
        
        $decodedData = [];
        
        // Socket.IO v4协议标准：遍历数据数组，解码占位符
        foreach ($packet['data'] as $index => $item) {
            if (is_array($item) && isset($item['_placeholder']) && $item['_placeholder'] === true) {
                // 二进制数据占位符
                $placeholderNum = $item['num'] ?? null;
                if ($placeholderNum !== null) {
                    // 从socket的二进制附件中获取实际数据
                    $binaryData = $this->retrieveBinaryAttachment($socket, $placeholderNum);
                    if ($binaryData !== null) {
                        $decodedData[] = $binaryData;
                    } else {
                        echo "[socketio_v4_protocol_error] 未能获取二进制附件: placeholder_num={$placeholderNum}\n";
                        return false;
                    }
                }
            } else {
                // 常规数据直接传递
                $decodedData[] = $item;
            }
        }
        
        return $decodedData;
    }
    
    /**
     * 从socket实例中获取二进制附件数据
     */
    private function retrieveBinaryAttachment(array $socket, int $placeholderNum)
    {
        // Socket.IO v4协议：二进制附件存储在socket的二进制缓冲区中
        if (isset($socket['binaryAttachments']) && is_array($socket['binaryAttachments'])) {
            return $socket['binaryAttachments'][$placeholderNum] ?? null;
        }
        
        // 兼容性检查：可能存储在session或socket对象中
        if (isset($socket['session']) && is_object($socket['session'])) {
            $session = $socket['session'];
            if (isset($session->binaryData) && is_array($session->binaryData)) {
                return $session->binaryData[$placeholderNum] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * 处理错误包
     * @param array $packet 数据包
     * @param array $socket socket实例
     */
    private function handleError(array $packet, array $socket)
    {
        $error = $packet['error'] ?? 'Unknown error';
        echo "[socketio_v4_protocol_error] socket {$socket['id']} 错误: {$error}\n";
        return false;
    }

    /**
     * 验证授权信息
     * @param string $namespace 命名空间
     * @param mixed $auth 授权数据
     * @return bool 是否验证通过
     */
    private function validateAuth($namespace, $auth)
    {
        // 简单的验证逻辑，可根据需求扩展
        if (($namespace === '/' || $namespace === '/chat') && $auth === null) {
            return true; // 根命名空间和/chat命名空间不需要认证
        }
        
        // 其他命名空间的认证逻辑
        return is_array($auth) && isset($auth['token']) && !empty($auth['token']);
    }

    /**
     * 发送数据包
     * @param array $socket socket实例
     * @param array $packet 要发送的数据包
     */
    private function sendPacket(array $socket, array $packet)
    {
        // 实际的数据包发送逻辑
        // 会根据socket中存储的session信息发送
        
        echo "[event] sending packet to socket {$socket['id']}: " . json_encode($packet) . "\n";
        
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
                    
                    echo "[event] sending ACK packet: {$engineIOPacket}\n";
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
                    echo "[event] sending CONNECT packet: {$connectPacket}\n";
                    $session->send($connectPacket);
                    break;
                    
                case 'EVENT':
                    // 构建事件包
                    $eventName = $packet['event'] ?? '';
                    $eventData = $packet['data'] ?? [];
                    
                    $eventPacket = json_encode(array_merge([$eventName], $eventData));
                    $engineIOPacket = '42' . $eventPacket;
                    
                    echo "[event] sending EVENT packet: {$engineIOPacket}\n";
                    $session->send($engineIOPacket);
                    break;
                    
                case 'CONNECT_ERROR':
                    // 构建错误包
                    $error = $packet['error'] ?? 'Unknown error';
                    $errorPacket = json_encode(['error' => $error]);
                    $engineIOPacket = '44' . $errorPacket;
                    
                    echo "[event] sending CONNECT_ERROR packet: {$engineIOPacket}\n";
                    $session->send($engineIOPacket);
                    break;
                    
                default:
                    // 默认处理
                    $defaultPacket = json_encode($packet);
                    $engineIOPacket = '42' . $defaultPacket;
                    
                    echo "[event] sending default packet: {$engineIOPacket}\n";
                    $session->send($engineIOPacket);
                    break;
            }
        } else {
            echo "[event] no session found for socket {$socket['id']}\n";
        }
    }

    /**
     * 发送错误
     * @param array $socket socket实例
     * @param string $message 错误消息
     */
    private function sendError(array $socket, $message)
    {
        $this->sendPacket($socket, [
            'type' => 'CONNECT_ERROR',
            'namespace' => $socket['namespace'] ?? '/',
            'error' => $message
        ]);
    }

    /**
     * 发送ACK确认
     * @param array $socket socket实例
     * @param string $namespace 命名空间
     * @param int $ackId ACK ID
     * @param mixed $data 响应数据
     * 
     * Socket.IO v4协议标准修复：
     * - 数据类型统一处理：确保ACK响应数据格式与事件参数格式完全一致
     * - 协议一致性：ACK数据必须直接传递给客户端回调，不进行数组包装
     * - 双向参数传递保证：服务端发送[data1, data2]，客户端接收(data1, data2)
     * 
     * 协议文档参考：https://socket.io/docs/v4/socket-io-protocol/#acknowledgments
     */
    private function sendAck(array $socket, $namespace, $ackId, $data)
    {
        // 协议调试：确保ACK数据格式与事件参数格式完全一致
        echo "[socketio_v4_protocol] ACK响应: ID={$ackId}, 数据=", json_encode($data), "\n";
        
        // 直接构建并发送ACK数据包
        if (isset($socket['session']) && method_exists($socket['session'], 'send')) {
            $session = $socket['session'];
            
            // 构建ACK数据
            $ackData = [$data];
            $ackPacket = json_encode($ackData);
            
            // 构建Engine.IO数据包
            $engineIOPacket = $this->buildAckEngineIOPacket($namespace, $ackId, $ackPacket);
            
            echo "[socketio_v4_protocol] 发送ACK数据包: {$engineIOPacket}\n";
            
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
    private function buildAckEngineIOPacket($namespace, $ackId, $ackPacket): string
    {
        // Socket.IO v4 ACK格式: 43/chat,0[{"status":"ok"}]
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
            echo "❌ WebSocket错误: session对象没有send方法\n";
            return;
        }
        
        // 检查WebSocket连接状态
        if (method_exists($session, 'getStatus')) {
            $status = $session->getStatus();
            echo "[websocket] 连接状态: {$status}\n";
            if ($status !== 'CONNECTED') {
                echo "❌ WebSocket错误: 连接状态异常，无法发送消息\n";
                return;
            }
        }
        
        echo "[websocket] 开始发送响应到WebSocket...\n";
        try {
            $result = $session->send($message);
            echo "✅ WebSocket发送结果: " . ($result ? "成功" : "失败") . "\n";
        } catch (Exception $e) {
            echo "❌ WebSocket发送异常: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 存储ACK回调函数
     * @param array $socket socket实例
     * @param string $namespace 命名空间
     * @param int $ackId ACK ID
     * @param callable $callback 回调函数
     */
    public function storeAckCallback(array $socket, $namespace, $ackId, callable $callback)
    {
        $callbackKey = "{$socket['id']}:{$namespace}:{$ackId}";
        $this->ackCallbacks[$callbackKey] = $callback;
    }

    /**
     * 获取所有连接的socket
     * @return array socket列表
     */
    public function getConnectedSockets()
    {
        return $this->connectedSockets;
    }

    /**
     * 获取特定命名空间的事件处理器列表
     * @param string $namespace 命名空间
     * @return array 事件处理器数组
     */
    public function getEventHandlers($namespace = '/')
    {
        $namespace = $namespace === '' ? '/' : $namespace;
        
        if (!isset($this->namespaceHandlers[$namespace])) {
            return [];
        }
        
        return $this->namespaceHandlers[$namespace]['events'] ?? [];
    }
    
    /**
     * 检查是否存在事件处理器
     * @param string $namespace 命名空间
     * @param string $eventName 事件名称
     * @return bool 是否存在
     */
    public function hasEventHandler($namespace, $eventName)
    {
        $namespace = $namespace === '' ? '/' : $namespace;
        
        // 检查EventHandler级别注册的事件处理器
        if (isset($this->namespaceHandlers[$namespace]['events'][$eventName])) {
            return true;
        }
        
        return false;
    }

    /**
     * 触发事件处理（兼容方法）
     * @param Session $session 会话对象
     * @param string $namespace 命名空间
     * @param string $eventName 事件名称
     * @param array $args 事件参数
     */
    public function triggerEvent(Session $session, string $namespace = '/', string $eventName = '', array $args = [])
    {
        return $this->triggerEventWithAck($session, $namespace, $eventName, $args, null);
    }
    
    /**
     * 触发带ACK的事件处理
     */
    public function triggerEventWithAck(Session $session, string $namespace = '/', string $eventName = '', array $args = [], ?int $ackId = null): bool
    {
        // 构建socket信息
        $socket = [
            'id' => $session->getSid(),
            'session' => $session,
            'namespace' => $namespace
        ];
        
        // 处理特殊事件
        switch ($eventName) {
            case 'connection':
                $this->triggerConnect($socket, $namespace);
                return true;
            case 'disconnect':
                $this->triggerDisconnect($socket, 'client disconnect');
                return true;
        };
        
        // 处理自定义事件
        if ($eventName) {
            $packet = [
                'type' => 'EVENT',
                'namespace' => $namespace,
                'event' => $eventName,
                'data' => $args
            ];
            
            // 如果有ACK ID，添加到数据包中
            if ($ackId !== null) {
                $packet['id'] = $ackId;
            }
            
            return $this->handlePacket($packet, $socket);
        }
        
        return false;
    }
    
    /**
     * 分发事件（兼容方法）
     * @param Session $session 会话对象
     * @param string $eventName 事件名称
     * @param mixed $eventData 事件数据
     * @param array $socket Socket信息
     * @return bool 是否处理成功
     */
    public function dispatchEvent(Session $session, string $eventName, $eventData, array $socket): bool
    {
        return $this->triggerEvent($session, $socket['namespace'] ?? '/', $eventName, $eventData);
    }
}