<?php

namespace PhpSocketIO\Adapter;

/**
 * 基于Redis的集群适配器
 * 支持跨进程/集群Socket.IO消息传递
 */
class RedisAdapter implements AdapterInterface
{
    /** @var array 配置参数 */
    private $config;
    
    /** @var object Redis客户端实例 */
    private $redis;
    
    /** @var array 房间成员映射表 [room => [sid1, sid2, ...]] */
    private $rooms = [];
    
    /** @var array 会话房间映射表 [sid => [room1, room2, ...]] */
    private $sids = [];
    
    /** @var string Redis键前缀，避免键冲突 */
    private $prefix = 'socketio_';
    
    /** @var bool 是否已初始化 */
    private $initialized = false;
    
    /** @var int 当前进程ID */
    private $processId;
    
    /** @var int 重连尝试次数 */
    private $reconnectAttempts = 3;
    
    /** @var int 重连间隔时间（毫秒） */
    private $reconnectInterval = 1000;
    
    /** @var bool 是否正在重连 */
    private $isReconnecting = false;
    
    /**
     * 构造函数
     * @param array $config 配置参数
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => null,
            'db' => 0,
            'prefix' => 'socketio_',
            'heartbeat' => 25
        ], $config);
        
        $this->prefix = $this->config['prefix'];
        $this->processId = getmypid();
    }
    
    /**
     * 获取Redis客户端类名（动态检查）
     */
    private function getRedisClientClass(): string
    {
        if (!class_exists('\Workerman\Redis\Client')) {
            throw new \RuntimeException('Redis client not found. Please install workerman/redis via composer: composer require workerman/redis');
        }
        return '\Workerman\Redis\Client';
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
        
        // 连接Redis
        $this->connectRedis();
        
        // 订阅Redis频道
        $this->subscribeToChannels();
        
        $this->initialized = true;
        echo "[adapter] Redis adapter initialized with prefix: {$this->prefix}\n";
    }
    
    /**
     * 连接Redis
     */
    private function connectRedis(): void
    {
        $redisClientClass = $this->getRedisClientClass();
        $attempt = 0;
        $maxAttempts = $this->reconnectAttempts;
        
        while ($attempt <= $maxAttempts) {
            try {
                // 构建Redis连接URL
                $redisUrl = "redis://{$this->config['host']}:{$this->config['port']}";
                
                // 连接Redis
                $this->redis = new $redisClientClass($redisUrl, [
                    'connect_timeout' => 10
                ]);
                
                // 验证密码
                if ($this->config['auth']) {
                    $this->redis->auth($this->config['auth']);
                }
                
                // 选择数据库
                $this->redis->select($this->config['db']);
                
                echo "[adapter] Redis connected to {$this->config['host']}:{$this->config['port']}\n";
                $this->isReconnecting = false;
                return;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt > $maxAttempts) {
                    echo "[adapter] Redis connection failed after {$maxAttempts} attempts: " . $e->getMessage() . "\n";
                    throw new \RuntimeException("Failed to connect to Redis", 0, $e);
                }
                
                echo "[adapter] Redis connection attempt {$attempt}/{$maxAttempts} failed: " . $e->getMessage() . "\n";
                usleep($this->reconnectInterval * 1000); // 转换为微秒
            }
        }
    }
    
    /**
     * 尝试重新连接Redis
     */
    private function tryReconnect(): bool
    {
        if ($this->isReconnecting) {
            return false; // 避免重复重连
        }
        
        $this->isReconnecting = true;
        
        try {
            $this->connectRedis();
            echo "[adapter] Redis reconnected successfully\n";
            return true;
        } catch (\Exception $e) {
            echo "[adapter] Redis reconnection failed: " . $e->getMessage() . "\n";
            $this->isReconnecting = false;
            return false;
        }
    }
    
    /**
     * 执行Redis命令，带重连机制
     */
    private function executeRedisCommand(callable $callback, $retry = true)
    {
        try {
            // 直接执行命令，workerman/redis会异步执行并返回结果
            return $callback($this->redis);
        } catch (\Exception $e) {
            echo "[adapter] Redis command failed: " . $e->getMessage() . "\n";
            
            if ($retry && $this->tryReconnect()) {
                try {
                    return $callback($this->redis);
                } catch (\Exception $e2) {
                    echo "[adapter] Redis command failed after reconnection: " . $e2->getMessage() . "\n";
                    throw $e2;
                }
            }
            
            throw $e;
        }
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
        
        // 构建消息数据
        $data = [
            'packet' => $packet,
            'sender' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        // 发布到Redis频道
        $channel = $this->prefix . 'broadcast';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
        
        // 同时发送到本地所有会话
        $this->sendToLocalAll($packet);
        
        echo "[adapter] broadcasting packet to cluster\n";
    }
    
    /**
     * 发送消息到指定房间
     * @param string $room 房间名称
     * @param array $packet 消息包数据
     */
    public function to(string $room, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }
        
        // 构建消息数据
        $data = [
            'room' => $room,
            'packet' => $packet,
            'sender' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        // 发布到Redis频道
        $channel = $this->prefix . 'room';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
        
        // 同时发送到本地房间成员
        $this->sendToLocalRoom($room, $packet);
        
        echo "[adapter] publishing packet to room '{$room}'\n";
    }
    
    /**
     * 单发消息到指定会话
     * @param string $sid 目标会话ID
     * @param array $packet 消息包数据
     */
    public function emit(string $sid, array $packet): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Adapter not initialized');
        }
        
        // 检查会话是否在本地
        if (isset($this->sids[$sid])) {
            // 会话在本地，直接发送
            $this->sendToLocalSession($sid, $packet);
        } else {
            // 会话不在本地，通过Redis发送
            $data = [
                'sid' => $sid,
                'packet' => $packet,
                'sender' => $this->processId,
                'timestamp' => microtime(true)
            ];
            
            $channel = $this->prefix . 'send';
            $this->executeRedisCommand(function($redis) use ($channel, $data) {
                $redis->publish($channel, json_encode($data));
            });
        }
    }
    
    /**
     * 添加房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function join(string $sid, string $room): void
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
        
        // 同步到Redis
        $roomKey = $this->prefix . 'room:' . $room;
        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($roomKey, $sidKey, $sid, $room) {
            $redis->sAdd($roomKey, $sid);
            $redis->sAdd($sidKey, $room);
        });
        
        // 通知其他进程成员变动
        $data = [
            'action' => 'add',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        $channel = $this->prefix . 'member';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
    }
    
    /**
     * 移除房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function leave(string $sid, string $room): void
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
        
        // 同步到Redis
        $roomKey = $this->prefix . 'room:' . $room;
        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($roomKey, $sidKey, $sid, $room) {
            $redis->sRem($roomKey, $sid);
            $redis->sRem($sidKey, $room);
        });
        
        // 通知其他进程成员变动
        $data = [
            'action' => 'del',
            'sid' => $sid,
            'room' => $room,
            'process_id' => $this->processId,
            'timestamp' => microtime(true)
        ];
        
        $channel = $this->prefix . 'member';
        $this->executeRedisCommand(function($redis) use ($channel, $data) {
            $redis->publish($channel, json_encode($data));
        });
    }
    
    /**
     * 清理会话关联的房间
     * @param string $sid 会话ID
     */
    public function remove(string $sid): void
    {
        if (isset($this->sids[$sid])) {
            $rooms = $this->sids[$sid];
            foreach ($rooms as $room) {
                $this->leave($sid, $room);
            }
            unset($this->sids[$sid]);
        }
        
        // 从Redis中删除会话相关的所有数据
        $sidKey = $this->prefix . 'sid:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sidKey) {
            $redis->del($sidKey);
        });
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
     * 注册会话
     * @param string $sid 会话ID
     */
    public function register(string $sid): void
    {
        // 在Redis中记录会话与进程的映射
        $sessionKey = $this->prefix . 'session:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sessionKey, $sid) {
            $redis->set($sessionKey, $this->processId);
            $redis->expire($sessionKey, 3600); // 1小时过期
        });
    }
    
    /**
     * 注销会话
     * @param string $sid 会话ID
     */
    public function unregister(string $sid): void
    {
        // 从Redis中删除会话与进程的映射
        $sessionKey = $this->prefix . 'session:' . $sid;
        $this->executeRedisCommand(function($redis) use ($sessionKey) {
            $redis->del($sessionKey);
        });
    }
    
    /**
     * 关闭适配器连接
     */
    public function close(): void
    {
        if ($this->redis) {
            $this->redis->close();
        }
        $this->initialized = false;
    }
    
    /**
     * 发送消息到本地房间成员
     * @param string $room 房间名称
     * @param array $packet 消息包数据
     */
    private function sendToLocalRoom(string $room, array $packet): void
    {
        if (isset($this->rooms[$room]) && !empty($this->rooms[$room])) {
            $sessionClass = '\PhpSocketIO\Session';
            if (class_exists($sessionClass)) {
                foreach ($this->rooms[$room] as $sid) {
                    $sessionClass::sendToSession($sid, $packet);
                }
            }
        }
    }
    
    /**
     * 发送消息到本地所有会话
     * @param array $packet 消息包数据
     */
    private function sendToLocalAll(array $packet): void
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $activeSessions = $sessionClass::all();
            foreach ($activeSessions as $sid => $session) {
                $sessionClass::sendToSession($sid, $packet);
            }
        }
    }
    
    /**
     * 发送消息到本地会话
     * @param string $sid 会话ID
     * @param array $packet 消息包数据
     */
    private function sendToLocalSession(string $sid, array $packet): void
    {
        $sessionClass = '\PhpSocketIO\Session';
        if (class_exists($sessionClass)) {
            $sessionClass::sendToSession($sid, $packet);
        }
    }
    
    /**
     * 订阅Redis频道
     */
    private function subscribeToChannels(): void
    {
        $redisClientClass = $this->getRedisClientClass();
        // 构建Redis连接URL
        $redisUrl = "redis://{$this->config['host']}:{$this->config['port']}";
        
        // 创建专门用于订阅的Redis客户端
        $this->subscriber = new $redisClientClass($redisUrl, [
            'connect_timeout' => 10
        ]);
        
        // 验证密码
        if ($this->config['auth']) {
            $this->subscriber->auth($this->config['auth']);
        }
        
        // 选择数据库
        $this->subscriber->select($this->config['db']);
        
        // 订阅频道
        $channels = [
            $this->prefix . 'broadcast',
            $this->prefix . 'room',
            $this->prefix . 'send',
            $this->prefix . 'member'
        ];
        
        // 保存当前实例的引用
        $adapter = $this;
        $processId = $this->processId;
        
        foreach ($channels as $channel) {
            $this->subscriber->subscribe($channel, function ($channel, $message) use ($adapter, $processId) {
                $adapter->handleRedisMessage($channel, $message, $processId);
            });
        }
        
        echo "[adapter] Redis subscriber started, subscribed to channels: " . implode(', ', $channels) . "\n";
    }
    
    /**
     * 处理Redis消息
     * @param string $channel 频道名称
     * @param string $message 消息内容
     * @param int $processId 进程ID
     */
    private function handleRedisMessage(string $channel, string $message, int $processId): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                echo "[adapter] Invalid message format: {$message}\n";
                return;
            }
            
            // 跳过自己发送的消息
            if (isset($data['sender']) && $data['sender'] === $processId) {
                return;
            }
            
            // 根据频道类型处理消息
            $prefix = $this->prefix;
            switch ($channel) {
                case $prefix . 'broadcast':
                    $this->handleBroadcastMessage($data);
                    break;
                case $prefix . 'room':
                    $this->handleRoomMessage($data);
                    break;
                case $prefix . 'send':
                    $this->handleSendMessage($data);
                    break;
                case $prefix . 'member':
                    $this->handleMemberMessage($data);
                    break;
                default:
                    echo "[adapter] Unknown channel: {$channel}\n";
            }
        } catch (\Exception $e) {
            echo "[adapter] Error handling Redis message: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 处理广播消息
     * @param array $data 消息数据
     */
    private function handleBroadcastMessage(array $data): void
    {
        if (isset($data['packet'])) {
            $this->sendToLocalAll($data['packet']);
        }
    }
    
    /**
     * 处理房间消息
     * @param array $data 消息数据
     */
    private function handleRoomMessage(array $data): void
    {
        if (isset($data['room']) && isset($data['packet'])) {
            $this->sendToLocalRoom($data['room'], $data['packet']);
        }
    }
    
    /**
     * 处理单发消息
     * @param array $data 消息数据
     */
    private function handleSendMessage(array $data): void
    {
        if (isset($data['sid']) && isset($data['packet'])) {
            $this->sendToLocalSession($data['sid'], $data['packet']);
        }
    }
    
    /**
     * 处理成员变动消息
     * @param array $data 消息数据
     */
    private function handleMemberMessage(array $data): void
    {
        // 成员变动消息由发送方处理，接收方无需处理
        // 因为每个进程都维护自己的本地房间和会话信息
    }
    
    /** @var object 用于订阅的Redis客户端实例 */
    private $subscriber;
}
