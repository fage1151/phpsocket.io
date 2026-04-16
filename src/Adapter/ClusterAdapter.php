<?php

namespace PhpSocketIO\Adapter;

use Channel\Client as ChannelClient;
use Channel\Server as ChannelServer;

/**
 * 基于Workerman\Channel的集群适配器
 * 支持跨进程/集群Socket.IO消息传递
 */
class ClusterAdapter implements AdapterInterface
{

    
    /** @var array 配置参数 */
    private $config;
    
    /** @var array 房间成员映射表 [room => [sid1, sid2, ...]] */
    private $rooms = [];
    
    /** @var array 会话房间映射表 [sid => [room1, room2, ...]] */
    private $sids = [];
    
    /** @var string 频道前缀，避免进程间冲突 */
    private $prefix = 'socketio_';
    
    /** @var bool 是否已初始化Channel服务 */
    private $initialized = false;
    
    /** @var array 会话进程映射表 [sid => process_id] */
    private $sessionProcessMap = [];
    
    /** @var int 当前进程ID */
    private $processId;
    
    /** @var array 消息队列，用于批处理发送的消息 */
    private $messageQueue = [];
    
    /** @var bool 批处理定时器是否已启动 */
    private $batchTimerStarted = false;
    
    /** @var int 单次批处理最大消息数量 */
    private $maxBatchSize = 50;
    
    /** @var float 批处理间隔时间（秒） */
    private $batchInterval = 0.1;
    
    /**
     * 构造函数
     * @param array $config 配置参数
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'channel_ip' => '127.0.0.1',
            'channel_port' => 2206,
            'prefix' => 'socketio_',
            'heartbeat' => 25
        ], $config);
        
        $this->prefix = $this->config['prefix'];
        $this->processId = getmypid();
    }
    
    /**
     * 初始化适配器
     * @param array $config 配置参数（可选，用于覆盖构造函数中的配置）
     */
    public function init(array $config = []): void
    {
        // 合并配置参数
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
            $this->prefix = $this->config['prefix'];
        }
        
        // 启动Channel客户端并订阅相关频道
        $this->initChannelClient();
        
        $this->initialized = true;
        echo "[adapter] Cluster adapter initialized with prefix: {$this->prefix}\n";
    }
    
    /**
     * Socket.IO v4集群协议增强：连接池优化和消息队列管理
     */
    
    /**
     * 初始化Channel客户端，实现连接池管理
     */
    private function initChannelClient(): void
    {
        try {
            // 连接到Channel服务器，实现连接池管理
            $connectionKey = $this->config['channel_ip'] . ':' . $this->config['channel_port'];
            ChannelClient::connect($this->config['channel_ip'], $this->config['channel_port']);
            
            echo "[adapter] Channel client connected to {$connectionKey}\n";
        } catch (\Exception $e) {
            echo "[adapter] Connection failed to {$this->config['channel_ip']}:{$this->config['channel_port']} - " . $e->getMessage() . "\n";
            throw new \RuntimeException("Failed to initialize channel client", 0, $e);
        }
        
        // 订阅广播频道
        ChannelClient::on($this->prefix . 'broadcast', function($packet) {
            $this->handleBroadcast($packet);
        });
        
        // 订阅房间广播频道
        ChannelClient::on($this->prefix . 'room', function($data) {
            $this->handleRoomMessage($data);
        });
        
        // 订阅成员管理频道
        ChannelClient::on($this->prefix . 'member', function($data) {
            $this->handleMemberChange($data);
        });
        
        // 订阅单发消息频道
        ChannelClient::on($this->prefix . 'send', function($data) {
            $this->handleSendMessage($data);
        });
        
        // 订阅会话进程注册频道（用于跨进程会话映射）
        ChannelClient::on($this->prefix . 'session_register', function($data) {
            $this->handleSessionRegister($data);
        });
        
        // 订阅会话查询频道（用于跨进程会话发现）
        ChannelClient::on($this->prefix . 'session_query', function($data) {
            $this->handleSessionQuery($data);
        });
        
        // 订阅会话查询响应频道
        ChannelClient::on($this->prefix . 'session_response', function($data) {
            $this->handleSessionResponse($data);
        });
        
        // 订阅会话探测频道
        ChannelClient::on($this->prefix . 'session_probe', function($data) {
            $this->handleSessionProbe($data);
        });
        
        // 启动会话映射心跳
        $this->startSessionMappingHeartbeat();
        
        // 启动会话清理定时器
        $this->startSessionCleanupTimer();
        
        // 心跳维持连接
        $this->startHeartbeat();
    }
    
    /**
     * 启动心跳维持连接
     */
    private function startHeartbeat(): void
    {
        // 每25秒发送一次心跳，避免连接断开
        \Workerman\Timer::add($this->config['heartbeat'], function() {
        try {
            // 使用批处理机制发送心跳消息（紧急发送）
            $this->publishBatch($this->prefix . 'heartbeat', [
                'pid' => getmypid(),
                'time' => time()
            ], true);
        } catch (\Exception $e) {
            echo "[adapter] heartbeat failed: " . $e->getMessage() . "\n";
            // 尝试重新连接
            $this->initChannelClient();
        }
        });
    }
    
    /**
     * 发布消息到指定房间
     * @param string $room 房间名称
     * @param array $packet 消息包数据
     */
    public function publish(string $room, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }
        
        // Socket.IO v4协议验证：确保房间消息包格式正确
        if (!$this->validateSocketIOV4Packet($packet)) {
            echo "[adapter error] invalid Socket.IO v4 packet for room '{$room}', publish cancelled: " . json_encode($packet) . "\n";
            return;
        }
        
        $data = [
            'room' => $room,
            'packet' => $packet,
            'sender' => getmypid(),
            'timestamp' => microtime(true)
        ];
        
        // 使用批处理机制发送房间消息
        $this->publishBatch($this->prefix . 'room', $data);
        echo "[adapter] publishing packet to room '{$room}': " . json_encode($packet) . "\n";
    }
    
    /**
     * 订阅房间消息（集群环境自动处理，无需显式订阅）
     */
    public function subscribe(string $room, callable $callback): void
    {
        // 集群环境中，所有进程都会收到房间消息
        // 具体的处理逻辑在handleRoomMessage方法中实现
    }
    
    /**
     * 取消订阅房间
     */
    public function unsubscribe(string $room): void
    {
        // 集群环境中，无法单独取消订阅
        // 通过rooms和sids的本地管理来实现
    }
    
    /**
     * 广播消息到所有进程
     * @param array $packet 消息包数据
     */
    public function broadcast(array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }
        
        // Socket.IO v4协议验证：确保广播包格式正确
        if (!$this->validateSocketIOV4Packet($packet)) {
            echo "[adapter error] invalid Socket.IO v4 packet, broadcast cancelled: " . json_encode($packet) . "\n";
            return;
        }
        
        $data = [
            'packet' => $packet,
            'sender' => getmypid(),
            'timestamp' => microtime(true)
        ];
        
        // 使用批处理机制发送广播消息
        $this->publishBatch($this->prefix . 'broadcast', $data);
        echo "[adapter] broadcasting packet to cluster: " . json_encode($packet) . "\n";
    }
    
    /**
     * 添加房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function add(string $sid, string $room): void
    {
        // 本地记录房间成员
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }
        
        if (!in_array($sid, $this->rooms[$room])) {
            $this->rooms[$room][] = $sid;
        }
        
        // 本地记录会话房间
        if (!isset($this->sids[$sid])) {
            $this->sids[$sid] = [];
        }
        
        if (!in_array($room, $this->sids[$sid])) {
            $this->sids[$sid][] = $room;
        }
        
        // 通知其他进程成员变动
        // 使用批处理机制发送成员添加消息
        $this->publishBatch($this->prefix . 'member', [
            'action' => 'add',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
    }
    
    /**
     * 删除房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function del(string $sid, string $room): void
    {
        // 本地移除房间成员
        if (isset($this->rooms[$room])) {
            $index = array_search($sid, $this->rooms[$room]);
            if ($index !== false) {
                array_splice($this->rooms[$room], $index, 1);
            }
            
            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }
        
        // 本地移除会话房间
        if (isset($this->sids[$sid])) {
            $index = array_search($room, $this->sids[$sid]);
            if ($index !== false) {
                array_splice($this->sids[$sid], $index, 1);
            }
            
            if (empty($this->sids[$sid])) {
                unset($this->sids[$sid]);
            }
        }
        
        // 通知其他进程成员变动
        // 使用批处理机制发送成员删除消息
        $this->publishBatch($this->prefix . 'member', [
            'action' => 'del',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
    }
    
    /**
     * 清理会话关联的房间
     * @param string $sid 会话ID
     */
    public function delAll(string $sid): void
    {
        if (isset($this->sids[$sid])) {
            $rooms = $this->sids[$sid];
            foreach ($rooms as $room) {
                $this->del($sid, $room);
            }
            unset($this->sids[$sid]);
        }
    }
    
    /**
     * 获取房间成员列表
     * @param string $room 房间名称
     * @return array 成员会话ID列表
     */
    public function clients(string $room): array
    {
        return $this->rooms[$room] ?? [];
    }
    
    /**
     * 获取会话所在的房间列表
     * @param string $sid 会话ID
     * @return array 房间名称列表
     */
    public function rooms(string $sid): array
    {
        return $this->sids[$sid] ?? [];
    }
    
    /**
     * 处理广播消息
     * @param array $data 广播数据
     */
    private function handleBroadcast(array $data): void
    {
        // 过滤自己发送的消息
        if ($data['sender'] === getmypid()) {
            return;
        }
        
        $packet = $data['packet'];
        
        // Socket.IO v4协议验证：确保广播包格式正确
        if (!$this->validateSocketIOV4Packet($packet)) {
            echo "[adapter warning] invalid Socket.IO v4 broadcast packet, dropping: " . json_encode($packet) . "\n";
            return;
        }
        
        // 直接向所有会话发送消息
        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $activeSessions = $sessionClass::all();
            foreach ($activeSessions as $sid => $session) {
                $sessionClass::sendToSession($sid, $packet);
            }
        }
        
        echo "[adapter] received broadcast from process {$data['sender']}\n";
    }
    
    /**
     * 处理房间消息
     * @param array $data 房间消息数据
     */
    private function handleRoomMessage(array $data): void
    {
        // 过滤自己发送的消息
        if ($data['sender'] === getmypid()) {
            return;
        }
        
        $room = $data['room'];
        $packet = $data['packet'];
        
        // Socket.IO v4协议验证：确保房间消息包格式正确
        if (!$this->validateSocketIOV4Packet($packet)) {
            echo "[adapter warning] invalid Socket.IO v4 room packet for room '{$room}', dropping: " . json_encode($packet) . "\n";
            return;
        }
        
        // 只在本地房间中有成员时才发送
        if (isset($this->rooms[$room]) && !empty($this->rooms[$room])) {
            // 直接向房间成员发送消息
            $sessionClass = '\PhpSocketIO\Session';
            if (class_exists($sessionClass)) {
                foreach ($this->rooms[$room] as $sid) {
                    $sessionClass::sendToSession($sid, $packet);
                }
            }
        }
        
        echo "[adapter] received room message for '{$room}' from process {$data['sender']}\n";
    }
    
    /**
     * 处理成员变动消息
     * @param array $data 成员变动数据
     */
    private function handleMemberChange(array $data): void
    {
        // 过滤自己发送的消息
        if ($data['sender'] === getmypid()) {
            return;
        }
        
        $action = $data['action'];
        $sid = $data['sid'];
        $room = $data['room'];
        
        switch ($action) {
            case 'add':
                if (!isset($this->rooms[$room])) {
                    $this->rooms[$room] = [];
                }
                if (!in_array($sid, $this->rooms[$room])) {
                    $this->rooms[$room][] = $sid;
                }
                
                if (!isset($this->sids[$sid])) {
                    $this->sids[$sid] = [];
                }
                if (!in_array($room, $this->sids[$sid])) {
                    $this->sids[$sid][] = $room;
                }
                break;
                
            case 'del':
                if (isset($this->rooms[$room])) {
                    $index = array_search($sid, $this->rooms[$room]);
                    if ($index !== false) {
                        array_splice($this->rooms[$room], $index, 1);
                    }
                }
                
                if (isset($this->sids[$sid])) {
                    $index = array_search($room, $this->sids[$sid]);
                    if ($index !== false) {
                        array_splice($this->sids[$sid], $index, 1);
                    }
                }
                break;
        }
        
        echo "[adapter] member change: {$action} sid={$sid} room={$room}\n";
    }
    
    /**
     * 单发消息到指定会话（跨进程单发消息）
     * @param string $sid 目标会话ID
     * @param array $packet 消息包数据
     */
    public function send(string $sid, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }
        
        // 获取会话所在的进程ID
        $targetProcess = $this->getSessionProcess($sid);
        
        // 如果会话在本地进程，直接发送
        if ($targetProcess === $this->processId) {
            $this->handleDirectSend($sid, $packet);
        } else {
            // 转发到目标进程
            $this->forwardToProcess($targetProcess, $sid, $packet);
        }
    }
    
    /**
     * 获取会话所在的进程ID
     * @param string $sid 会话ID
     * @return int 进程ID
     */
    private function getSessionProcess(string $sid): int
    {
        // 如果本地有会话映射记录，使用本地记录
        if (isset($this->sessionProcessMap[$sid])) {
            return $this->sessionProcessMap[$sid];
        }
        
        // 检查会话是否存在于本地
        if ($this->isSessionLocal($sid)) {
            $this->sessionProcessMap[$sid] = $this->processId;
            return $this->processId;
        }
        
        // 广播查询请求并等待响应
        return $this->discoverSessionProcess($sid);
    }
    
    /**
     * 检查会话是否在本地进程中
     * @param string $sid 会话ID
     * @return bool 是否在本地
     */
    private function isSessionLocal(string $sid): bool
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (!class_exists($sessionClass)) {
            return false;
        }
        
        $session = $sessionClass::get($sid);
        return $session !== null;
    }
    
    /**
     * 发现会话所在的进程ID（Socket.IO v4集群协议优化）
     * @param string $sid 会话ID
     * @return int 进程ID
     * @throws \RuntimeException 当会话确实不存在时
     */
    private function discoverSessionProcess(string $sid): int
    {
        // Socket.IO v4集群协议优化：使用查询ID跟踪并防止竞态条件
        $queryId = uniqid('query_', true);
        
        // 三级重试策略，适应生产环境网络延迟
        $retryConfigs = [
            ['timeout' => 0.1, 'description' => '快速查询'],    // 100ms
            ['timeout' => 0.3, 'description' => '标准重试'],    // 300ms 
            ['timeout' => 0.5, 'description' => '扩展重试']     // 500ms
        ];
        
        foreach ($retryConfigs as $config) {
            $result = $this->performSessionQuery($sid, $queryId, $config['timeout']);
            if ($result !== null) {
                echo "[adapter] session {$sid} found after {$config['description']}: process {$result}\n";
                return $result;
            }
        }
        
        // Socket.IO v4集群协议：最终检查和友好错误处理
        if ($this->isSessionAvailableAnywhere($sid)) {
            // 会话存在但查询失败，提供详细的错误信息
            throw new \RuntimeException(
                "Socket.IO v4 Session routing failed: Session {$sid} " .
                "exists in cluster but could not be routed after 0.9s timeout. " .
                "This may be due to network latency or high cluster load."
            );
        }
        
        // 会话确实不存在于任何集群节点
        throw new \RuntimeException(
            "Socket.IO v4 Session not found: Session {$sid} " .
            "does not exist in the cluster (checked all nodes). " .
            "The session may have been disconnected or expired."
        );
    }
    
    /**
     * 执行会话查询
     * @param string $sid 会话ID
     * @param string $queryId 查询ID
     * @param float $timeout 超时时间（秒）
     * @return int|null 进程ID或null
     */
    private function performSessionQuery(string $sid, string $queryId, float $timeout): ?int
    {
        $queryData = [
            'sid' => $sid,
            'query_id' => $queryId,
            'requester' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        // Socket.IO v4集群协议：标记会话为查询中状态
        $this->sessionProcessMap[$sid] = null; // null表示查询中
        // 使用批处理机制发送会话查询（超时敏感，使用紧急发送）
        $this->publishBatch($this->prefix . 'session_query', $queryData, true);
        
        $startTime = microtime(true);
        while (microtime(true) - $startTime < $timeout) {
            if (isset($this->sessionProcessMap[$sid]) && $this->sessionProcessMap[$sid] !== null) {
                // 提取进程ID并清理查询状态数据
                $sessionInfo = $this->sessionProcessMap[$sid];
                $this->sessionProcessMap[$sid] = $sessionInfo['processId']; // 保留基本进程ID
                return $sessionInfo['processId'];
            }
            usleep(10000); // 10ms
        }
        
        // Socket.IO v4集群协议：彻底清理查询状态
        if (isset($this->sessionProcessMap[$sid]) && $this->sessionProcessMap[$sid] === null) {
            unset($this->sessionProcessMap[$sid]);
        }
        
        return null;
    }
    
    /**
     * 检查会话是否在集群中的任何位置存在
     * @param string $sid 会话ID 
     * @return bool 是否存在
     */
    private function isSessionAvailableAnywhere(string $sid): bool
    {
        // 检查本地
        if ($this->isSessionLocal($sid)) {
            return true;
        }
        
        // 发送广播探测请求
        return $this->probeSessionExistence($sid);
    }
    
    /**
     * 探测会话是否存在
     * @param string $sid 会话ID
     * @return bool 是否存在
     */
    private function probeSessionExistence(string $sid): bool
    {
        // 发送探测请求，等待响应
        $probeId = uniqid('probe_', true);
        $probeData = [
            'sid' => $sid,
            'probe_id' => $probeId,
            'requester' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        // 使用批处理机制发送会话探测（超时敏感，使用紧急发送）
        $this->publishBatch($this->prefix . 'session_probe', $probeData, true);
        
        // 等待100ms响应
        $startTime = microtime(true);
        while (microtime(true) - $startTime < 0.1) {
            // 如果有任何进程响应了查询，认为会话存在
            if (isset($this->sessionProcessMap[$sid])) {
                return true;
            }
            usleep(10000); // 10ms
        }
        
        return false;
    }
    
    /**
     * 在本地进程中直接发送消息
     * @param string $sid 会话ID
     * @param array $packet 消息包数据
     */
    private function handleDirectSend(string $sid, array $packet): void
    {
        // 获取SocketIOServer的Session实例并发送消息
        $sessionClass = '\\PhpSocketIO\\Session';
        if (!class_exists($sessionClass)) {
            throw new \RuntimeException('Session class not found');
        }
        
        $session = $sessionClass::get($sid);
        if ($session) {
            // 适配器协议兼容性修复：必须发送完整的Socket.IO数据包
            // 错误方式：只发送data部分导致EventHandler接收不到事件类型信息
            // 正确方式：发送完整的事件数据包，包含type、event、data等字段
            
            if (isset($packet['type'])) {
                // 调用Session::sendToSession方法处理完整的Socket.IO包
                $sessionClass::sendToSession($sid, $packet);
                echo "[adapter] sent full packet to local session sid={$sid}: " . json_encode($packet) . "\n";
            } else {
                // 回退到只发送数据（兼容旧格式）
                if (isset($packet['data'])) {
                    $session->send($packet['data']);
                    echo "[adapter] sent data-only message to local session sid={$sid}\n";
                } else {
                    echo "[adapter] missing packet type and data, skipping send\n";
                }
            }
        } else {
            echo "[adapter] session not found locally sid={$sid}\n";
        }
    }
    
    /**
     * 转发消息到其他进程
     * @param int $targetProcess 目标进程ID
     * @param string $sid 会话ID
     * @param array $packet 消息包数据
     */
    private function forwardToProcess(int $targetProcess, string $sid, array $packet): void
    {
        // Socket.IO v4协议验证：确保数据包格式正确
        if (!$this->validateSocketIOV4Packet($packet)) {
            echo "[adapter warning] invalid Socket.IO v4 packet format, dropping: " . json_encode($packet) . "\n";
            return;
        }
        
        $data = [
            'target_sid' => $sid,
            'packet' => $packet,
            'sender' => $this->processId,
            'target_process' => $targetProcess,
            'timestamp' => microtime(true)
        ];
        
        // 使用批处理机制发送单发消息
        $this->publishBatch($this->prefix . 'send', $data);
        echo "[adapter] forwarded message for sid={$sid} to process {$targetProcess}\n";
    }
    
    /**
     * 验证Socket.IO v4协议数据包格式
     * @param array $packet 数据包
     * @return bool 是否符合Socket.IO v4协议
     */
    private function validateSocketIOV4Packet(array $packet): bool
    {
        // 必需字段验证
        if (!isset($packet['type'])) {
            return false;
        }
        
        // 事件包验证 (type = 2)
        if ($packet['type'] === 2) {
            if (!isset($packet['event']) || !is_string($packet['event'])) {
                return false;
            }
            
            // data字段必须是数组（Socket.IO v4协议：单参数传递数据数组）
            if (isset($packet['data']) && !is_array($packet['data'])) {
                return false;
            }
        }
        
        // ACK包验证 (type = 3)
        if ($packet['type'] === 3) {
            if (!isset($packet['id']) || !is_numeric($packet['id'])) {
                return false;
            }
        }
        
        // 连接包验证 (type = 0)
        if ($packet['type'] === 0) {
            // 连接包可以包含初始数据，但不是必需的
            return true;
        }
        
        // 断开包验证 (type = 1)
        if ($packet['type'] === 1) {
            // 断开包没有其他必需字段
            return true;
        }
        
        // 心跳包验证 (type = 6)
        if ($packet['type'] === 6) {
            // 心跳包没有其他必需字段
            return true;
        }
        
        // CONNECT_ERROR包验证 (type = 4)
        if ($packet['type'] === 4) {
            // CONNECT_ERROR包应该包含错误数据
            if (!isset($packet['data']) || !is_array($packet['data'])) {
                return false;
            }
        }
        
        // 二进制事件包验证 (type = 5)
        if ($packet['type'] === 5) {
            // 二进制事件包需要事件名和附件编号
            if (!isset($packet['event']) || !is_string($packet['event'])) {
                return false;
            }
            // 二进制ATTACHMENT包验证：BINARY_ACK（type = 6）
        } else if ($packet['type'] === 6) {
            // BINARY_ACK包需要ACK ID
            if (!isset($packet['id']) || !is_numeric($packet['id'])) {
                return false;
            }
        }
        
        // 类型验证：确保类型在有效范围内
        if (!in_array($packet['type'], [0, 1, 2, 3, 4, 5, 6])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 处理单发消息
     * @param array $data 消息数据
     */
    private function handleSendMessage(array $data): void
    {
        // 检查消息是否发给当前进程
        if ($data['target_process'] !== $this->processId) {
            return;
        }
        
        $sid = $data['target_sid'];
        $packet = $data['packet'];
        
        // 在本地进程中处理消息
        $this->handleDirectSend($sid, $packet);
        
        echo "[adapter] received forwarded message for sid={$sid}\n";
    }
    
    /**
     * 本地注册会话（当会话在本进程创建时调用）
     * @param string $sid 会话ID
     */
    public function registerSession(string $sid): void
    {
        $this->sessionProcessMap[$sid] = $this->processId;
        
        // 通知其他进程会话注册信息
        ChannelClient::publish($this->prefix . 'session_register', [
            'sid' => $sid,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ]);
        
        echo "[adapter] registered session sid={$sid} in process {$this->processId}\n";
    }
    
    /**
     * 本地注销会话（当会话在本进程关闭时调用）
     * @param string $sid 会话ID
     */
    public function unregisterSession(string $sid): void
    {
        if (isset($this->sessionProcessMap[$sid])) {
            unset($this->sessionProcessMap[$sid]);
            echo "[adapter] unregistered session sid={$sid}\n";
        }
    }
    
    /**
     * 处理会话注册消息
     * @param array $data 注册数据
     */
    private function handleSessionRegister(array $data): void
    {
        $sid = $data['sid'];
        $processId = $data['process_id'];
        
        // 更新会话映射表（跳过自己发送的注册消息）
        if ($processId !== $this->processId) {
            $this->sessionProcessMap[$sid] = $processId;
            echo "[adapter] updated session mapping: sid={$sid} -> process={$processId}\n";
        }
    }
    
    /**
     * 处理会话查询消息
     * @param array $data 查询数据
     */
    private function handleSessionQuery(array $data): void
    {
        $sid = $data['sid'];
        $queryId = $data['query_id'];
        $requester = $data['requester'];
        
        // 过滤自己发送的查询
        if ($requester === $this->processId) {
            return;
        }
        
        // 检查会话是否在本地
        if ($this->isSessionLocal($sid)) {
            // 响应查询请求者
            ChannelClient::publish($this->prefix . 'session_response', [
                'sid' => $sid,
                'query_id' => $queryId,
                'responder' => $this->processId,
                'process_id' => $this->processId,
                'timestamp' => microtime(true)
            ]);
            echo "[adapter] responded to session query for sid={$sid}\n";
        }
    }
    
    /**
     * 处理会话查询响应（Socket.IO v4集群协议优化）
     * @param array $data 响应数据
     */
    private function handleSessionResponse(array $data): void
    {
        $sid = $data['sid'];
        $queryId = $data['query_id'];
        $processId = $data['process_id'];
        $responder = $data['responder'] ?? $processId;
        
        // 过滤自己发送的响应（避免自我循环）
        if ($responder === $this->processId) {
            echo "[adapter] skipping self-response for session query sid={$sid}\n";
            return;
        }
        
        // Socket.IO v4集群协议优化：查询ID匹配验证
        // 只有当会话正在查询中（sessionProcessMap[$sid] === null）时才接受响应
        if (!isset($this->sessionProcessMap[$sid]) || $this->sessionProcessMap[$sid] !== null) {
            echo "[adapter] ignoring outdated session response for sid={$sid} (not currently querying)\n";
            return;
        }
        
        // 增强查询ID验证：确保响应匹配当前的查询请求
        // 注意：当sessionProcessMap[$sid]为null时，表示查询中状态
        // 这种情况下我们接受任何有效的响应，因为可能会有多个并发查询
        
        // 更新会话映射表，包含响应时间戳和来源信息
        $this->sessionProcessMap[$sid] = [
            'processId' => $processId,
            'responder' => $responder,
            'queryId' => $queryId,
            'responseTime' => microtime(true)
        ];
        
        echo "[adapter] received valid session response: sid={$sid} -> process={$processId}\n";
    }
    
    /**
     * 处理会话探测消息
     * @param array $data 探测数据
     */
    private function handleSessionProbe(array $data): void
    {
        $sid = $data['sid'];
        $probeId = $data['probe_id'];
        $requester = $data['requester'];
        
        // 过滤自己发送的探测
        if ($requester === $this->processId) {
            return;
        }
        
        // 只有在会话确实在本地时才响应
        if ($this->isSessionLocal($sid)) {
            ChannelClient::publish($this->prefix . 'session_response', [
                'sid' => $sid,
                'query_id' => $probeId,
                'process_id' => $this->processId,
                'timestamp' => microtime(true)
            ]);
            echo "[adapter] responded to session probe for sid={$sid}\n";
        }
    }
    
    /**
     * 启动会话映射心跳
     */
    private function startSessionMappingHeartbeat(): void
    {
        // 每30秒发送一次会话映射心跳，保持映射表同步
        \Workerman\Timer::add(30, function() {
            if (empty($this->sessionProcessMap)) {
                return;
            }
            
            ChannelClient::publish($this->prefix . 'session_heartbeat', [
                'process_id' => $this->processId,
                'sessions' => array_keys($this->sessionProcessMap),
                'timestamp' => microtime(true)
            ]);
        });
    }
    
    /**
     * 启动会话清理定时器（Socket.IO v4集群协议优化）
     */
    private function startSessionCleanupTimer(): void
    {
        // Socket.IO v4集群协议：每60秒清理过期的会话映射
        \Workerman\Timer::add(60, function() {
            $currentTime = microtime(true);
            $expirationTime = $currentTime - 120; // 2分钟过期
            $cleanupCount = 0;
            
            // 清理过期的会话进程映射
            foreach ($this->sessionProcessMap as $sid => $sessionInfo) {
                // 检查响应时间戳，过期的映射需要清理
                if (is_array($sessionInfo) && isset($sessionInfo['responseTime'])) {
                    if ($sessionInfo['responseTime'] < $expirationTime) {
                        unset($this->sessionProcessMap[$sid]);
                        $cleanupCount++;
                    }
                } elseif ($sessionInfo === null) {
                    // 清理长时间处于查询状态的会话（超过3分钟）
                    unset($this->sessionProcessMap[$sid]);
                    $cleanupCount++;
                }
            }
            
            // Socket.IO v4集群协议：清理本地不再存在的会话
            $sessionClass = '\\PhpSocketIO\\Session';
            if (class_exists($sessionClass)) {
                foreach (array_keys($this->sessionProcessMap) as $sid) {
                    if (!$sessionClass::get($sid)) {
                        unset($this->sessionProcessMap[$sid]);
                        $cleanupCount++;
                    }
                }
            }
            
            echo "[adapter] session mapping cleanup completed: {$cleanupCount} expired entries removed\n";
        });
    }
    
    /**
     * 关闭适配器连接
     */
    public function close(): void
    {
        // Channel客户端自动管理连接，无需手动关闭
        $this->initialized = false;
    }
    
    /**
     * 获取适配器状态信息
     * @return array 状态信息
     */
    public function getStats(): array
    {
        return [
            'rooms_count' => count($this->rooms),
            'sessions_count' => count($this->sids),
            'initialized' => $this->initialized,
            'prefix' => $this->prefix,
            'message_queue_size' => count($this->messageQueue),
            'batch_timer_started' => $this->batchTimerStarted
        ];
    }
    
    /**
     * Socket.IO v4集群协议增强：消息批处理机制
     */
    
    /**
     * 延迟发布消息到消息队列（用于批处理优化）
     * @param string $channel 频道名称
     * @param array $data 消息数据
     * @param bool $urgent 是否紧急发送（不加入队列）
     */
    private function publishBatch(string $channel, array $data, bool $urgent = false): void
    {
        // 心跳消息需要立即发送，不加入队列
        if ($urgent || strpos($channel, 'heartbeat') !== false || strpos($channel, 'session_') !== false) {
            try {
                ChannelClient::publish($channel, $data);
                return;
            } catch (\Exception $e) {
                echo "[adapter] Urgent publish failed: " . $e->getMessage() . "\n";
                // 重新连接并重试
                $this->initChannelClient();
                try {
                    ChannelClient::publish($channel, $data);
                } catch (\Exception $e2) {
                    echo "[adapter] Retry failed, dropping urgent message: " . $e2->getMessage() . "\n";
                }
                return;
            }
        }
        
        // 普通消息加入批处理队列
        $this->messageQueue[] = [
            'channel' => $channel,
            'data' => $data,
            'timestamp' => microtime(true)
        ];
        
        // 如果队列达到最大大小，立即发送
        if (count($this->messageQueue) >= $this->maxBatchSize) {
            $this->flushMessageQueue();
        } else if (!$this->batchTimerStarted) {
            // 启动定时器处理队列
            $this->startBatchTimer();
        }
    }
    
    /**
     * 启动批处理定时器
     */
    private function startBatchTimer(): void
    {
        if ($this->batchTimerStarted) {
            return;
        }
        
        $this->batchTimerStarted = true;
        \Workerman\Timer::add($this->batchInterval, function($timerId) {
            if (empty($this->messageQueue)) {
                $this->batchTimerStarted = false;
                \Workerman\Timer::del($timerId); // 正确停止定时器
                return;
            }
            
            $this->flushMessageQueue();
            
            // 如果队列清空，停止定时器
            if (empty($this->messageQueue)) {
                $this->batchTimerStarted = false;
                \Workerman\Timer::del($timerId);
            }
        });
        
        echo "[adapter] Batch timer started with interval: {$this->batchInterval}s\n";
    }
    
    /**
     * 批量发送消息队列
     */
    private function flushMessageQueue(): void
    {
        if (empty($this->messageQueue)) {
            return;
        }
        
        $messages = array_splice($this->messageQueue, 0, $this->maxBatchSize);
        $batchCount = count($messages);
        
        try {
            foreach ($messages as $message) {
                ChannelClient::publish($message['channel'], $message['data']);
            }
            
            if ($batchCount > 1) {
                $latency = microtime(true) - $messages[0]['timestamp'];
                echo "[adapter] Flushed {$batchCount} messages in batch (latency: " . round($latency * 1000, 2) . "ms)\n";
            }
        } catch (\Exception $e) {
            echo "[adapter] Batch publish failed: " . $e->getMessage() . ", requeuing {$batchCount} messages\n";
            
            // 重新连接
            $this->initChannelClient();
            
            // 将失败的消息重新加入队列
            $this->messageQueue = array_merge($messages, $this->messageQueue);
        }
    }
    
    /**
     * Socket.IO v4集群协议增强：连接健康检查和重连机制
     */
    
    /**
     * 检查Channel连接健康状态
     * @return bool 连接是否健康
     */
    private function checkChannelHealth(): bool
    {
        try {
            // 发送一个测试消息检查连接状态
            $testData = ['test' => 'health_check', 'timestamp' => microtime(true)];
            ChannelClient::publish($this->prefix . 'health_check', $testData);
            return true;
        } catch (\Exception $e) {
            echo "[adapter] Health check failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * 连接池优化：定期健康检查和重连
     */
    private function startConnectionHealthCheck(): void
    {
        \Workerman\Timer::add(30, function() {
            if (!$this->checkChannelHealth()) {
                echo "[adapter] Channel connection unhealthy, attempting to reconnect\n";
                $this->initChannelClient();
            }
        });
        
        echo "[adapter] Connection health check started (interval: 30s)\n";
    }
}