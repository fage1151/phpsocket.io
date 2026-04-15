<?php

namespace PhpSocketIO;

/**
 * HTTP轮询处理器 - 处理Socket.IO的HTTP轮询请求
 */
class PollingHandler
{
    private ServerManager $serverManager;
    private EngineIOHandler $engineIoHandler;
    private MiddlewareHandler $middlewareHandler;

    public function __construct(ServerManager $serverManager, EngineIOHandler $engineIoHandler, MiddlewareHandler $middlewareHandler)
    {
        $this->serverManager = $serverManager;
        $this->engineIoHandler = $engineIoHandler;
        $this->middlewareHandler = $middlewareHandler;
    }

    /**
     * 处理HTTP轮询请求
     */
    public function handlePolling(\Workerman\Connection\TcpConnection $connection, $req): void
    {
        $method = $req->method();
        $sid    = $req->get('sid');

        if ($method === 'OPTIONS') {
            $this->sendHttpResponse($connection, 200, [], '');
            return;
        }

        match ($method) {
            'GET' => $sid === null 
                ? $this->handlePollingNew($connection) 
                : $this->handlePollingGet($connection, $sid),
            'POST' => $this->handlePollingPost($connection, $sid, $req->rawBody() ?? ''),
            default => $this->sendErrorResponse($connection, 'Method not allowed'),
        };
    }

    /**
     * 处理新连接的轮询请求
     */
    private function handlePollingNew(\Workerman\Connection\TcpConnection $connection): void
    {
        $session = new Session(Session::generateSid());
        $session->transport = 'polling';

        // Socket.IO v4握手响应
        $body = '0' . json_encode([
            'sid'            => $session->sid,
            'upgrades'       => ['websocket'],
            'pingInterval'   => $this->serverManager->getPingInterval(),
            'pingTimeout'    => $this->serverManager->getPingTimeout(),
            'maxPayload'     => 1000000,
            'supportsBinary' => true,
        ]);

        $this->sendHttpResponse($connection, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $body);

        $session->namespaces['/'] = [];
        
        // 触发连接事件
        $socket = ['nsp' => '/', 'id' => $session->sid];
        $eventHandler = $this->engineIoHandler->getEventHandler();
        if ($eventHandler) {
            $eventHandler->triggerConnect($socket, '/');
        }

        echo "[connect] polling sid={$session->sid}\n";
    }

    /**
     * 处理获取消息的轮询请求
     */
    private function handlePollingGet(\Workerman\Connection\TcpConnection $connection, string $sid): void
    {
        $startTime = microtime(true);
        
        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}");
            return;
        }

        // 检查会话是否超时
        if ($this->isSessionTimeout($session)) {
            Session::remove($sid);
            $this->sendErrorResponse($connection, "Session timeout: {$sid}");
            return;
        }

        // 更新会话的最后活动时间
        $session->lastPong = time();

        $messages = $session->flush();
        if (empty($messages)) {
            // 动态调整长轮询超时时间，根据服务器负载和会话活跃度
            $timeout = $this->calculatePollingTimeout($session);
            
            // 长轮询：延迟指定时间再响应
            $timerId = \Workerman\Timer::add($timeout, function() use ($connection, $sid) {
                // 再次检查是否有新消息
                $session = Session::get($sid);
                if ($session) {
                    $messages = $session->flush();
                    if (!empty($messages)) {
                        $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
                        return;
                    }
                }
                // 超时，发送空消息
                $this->sendHttpResponse($connection, 200, [], '6');
            }, [], false);
            
            // 存储定时器ID，以便在连接关闭时取消定时器
            $connection->timerId = $timerId;
        } else {
            // 消息分批发送，避免单次响应过大
            $batchSize = 10; // 每批最多10条消息
            $batches = array_chunk($messages, $batchSize);
            
            foreach ($batches as $batch) {
                $this->sendHttpResponse($connection, 200, [], implode("\x1e", $batch));
            }
        }
        
        // 记录处理时间
        $processingTime = microtime(true) - $startTime;
        if ($processingTime > 1) {
            echo "[performance] Polling GET request took {$processingTime}s for sid={$sid}\n";
        }
    }

    /**
     * 处理发送消息的轮询请求
     */
    private function handlePollingPost(\Workerman\Connection\TcpConnection $connection, ?string $sid, string $body): void
    {
        $startTime = microtime(true);
        
        if ($sid === null) {
            $this->sendErrorResponse($connection, 'Missing sid');
            return;
        }

        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}");
            return;
        }

        // 更新会话的最后活动时间
        $session->lastPong = time();

        try {
            $this->processPollingData($session, $body);
            $this->sendHttpResponse($connection, 200, [], 'ok');
        } catch (\Exception $e) {
            $this->sendErrorResponse($connection, $e->getMessage());
        }
        
        // 记录处理时间
        $processingTime = microtime(true) - $startTime;
        if ($processingTime > 1) {
            echo "[performance] Polling POST request took {$processingTime}s for sid={$sid}\n";
        }
    }

    /**
     * 处理轮询接收到的数据
     */
    private function processPollingData(Session $session, string $data): void
    {
        if (empty($data)) {
            throw new \Exception('Empty data received');
        }

        // 支持multiple JSON payloads，用分隔符分隔
        $packets = explode("\x1e", $data);
        $packetCount = count($packets);
        
        if ($packetCount > 100) {
            // 限制单次处理的数据包数量，防止DoS攻击
            throw new \Exception('Too many packets in single request');
        }
        
        foreach ($packets as $packet) {
            if (strlen($packet) === 0) continue;
            
            // 限制单条数据包的大小，防止DoS攻击
            if (strlen($packet) > 1024 * 1024) { // 1MB
                throw new \Exception('Packet too large');
            }
            
            // 处理单条数据包
            $decodedPacket = PacketParser::parseEngineIOPacket($packet);
            
            if ($decodedPacket) {
                $this->engineIoHandler->handlePacket($packet, $decodedPacket, null, $session);
            }
        }
        
        if ($packetCount > 10) {
            echo "[performance] Processed {$packetCount} packets for sid={$session->sid}\n";
        }
    }

    /**
     * 发送HTTP响应
     */
    private function sendHttpResponse(\Workerman\Connection\TcpConnection $connection, int $code, array $extraHeaders, string $body, bool $closeConn = true): void
    {
        // 取消连接的定时器，避免内存泄漏
        $this->cancelConnectionTimer($connection);
        
        // 默认CORS配置
        $defaultHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET,POST,OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
            'Connection' => 'close',
            'Content-Type' => 'text/plain; charset=UTF-8'
        ];
        
        // 使用ServerManager中的CORS配置覆盖默认值
        $corsConfig = $this->serverManager->getCors();
        if ($corsConfig !== null) {
            $defaultHeaders['Access-Control-Allow-Origin'] = $corsConfig['origin'] ?? $defaultHeaders['Access-Control-Allow-Origin'];
            
            if (isset($corsConfig['methods'])) {
                $defaultHeaders['Access-Control-Allow-Methods'] = is_array($corsConfig['methods']) 
                    ? implode(',', $corsConfig['methods']) 
                    : $corsConfig['methods'];
            }
            
            if (isset($corsConfig['allowedHeaders'])) {
                $defaultHeaders['Access-Control-Allow-Headers'] = is_array($corsConfig['allowedHeaders']) 
                    ? implode(',', $corsConfig['allowedHeaders']) 
                    : $corsConfig['allowedHeaders'];
            }
            
            if (isset($corsConfig['credentials']) && $corsConfig['credentials']) {
                $defaultHeaders['Access-Control-Allow-Credentials'] = 'true';
            }
        }
        
        $response = new \Workerman\Protocols\Http\Response(
            $code, 
            array_merge($defaultHeaders, $extraHeaders), 
            $body
        );
        $connection->send($response);
        
        if ($closeConn) {
            $connection->close();
        }
    }

    /**
     * 发送错误响应
     */
    private function sendErrorResponse(\Workerman\Connection\TcpConnection $connection, string $message = 'Error', int $code = 400): void
    {
        echo "[error] {$message}\n";
        $this->sendHttpResponse($connection, $code, [], $message);
    }
    
    /**
     * 获取服务器负载
     * @return float 服务器负载值（0-10）
     */
    private function getServerLoad(): float
    {
        // 尝试获取系统负载
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            // 返回1分钟负载平均值，最大限制为10
            return min(10, $load[0]);
        }
        
        //  fallback：返回固定值
        return 0;
    }
    
    /**
     * 检查会话是否超时
     * @param Session $session 会话对象
     * @return bool 是否超时
     */
    private function isSessionTimeout(Session $session): bool
    {
        $lastPong = property_exists($session, 'lastPong') ? $session->lastPong : 0;
        $timeout = $this->serverManager->getPingTimeout() * 1000; // 转换为毫秒
        return (time() - $lastPong) > ($timeout / 1000);
    }
    
    /**
     * 计算轮询超时时间
     * @param Session $session 会话对象
     * @return int 超时时间（秒）
     */
    private function calculatePollingTimeout(Session $session): int
    {
        // 基础超时时间
        $baseTimeout = 10;
        
        // 根据服务器负载调整
        $load = $this->getServerLoad();
        $loadAdjustment = max(1, $baseTimeout - $load);
        
        // 根据会话活跃度调整
        $lastPong = property_exists($session, 'lastPong') ? $session->lastPong : 0;
        $inactivityTime = time() - $lastPong;
        $activityAdjustment = min(5, max(1, $inactivityTime / 60));
        
        // 计算最终超时时间
        $timeout = min($baseTimeout, max(1, $loadAdjustment - $activityAdjustment));
        
        return (int)$timeout;
    }
    
    /**
     * 取消连接的定时器
     * @param \Workerman\Connection\TcpConnection $connection 连接对象
     */
    private function cancelConnectionTimer(\Workerman\Connection\TcpConnection $connection): void
    {
        if (property_exists($connection, 'timerId')) {
            \Workerman\Timer::del($connection->timerId);
            unset($connection->timerId);
        }
    }
}