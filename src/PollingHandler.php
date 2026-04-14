<?php

namespace PhpSocketIO;

/**
 * HTTP轮询处理器 - 处理Socket.IO的HTTP轮询请求
 */
class PollingHandler
{
    private $serverManager;
    private $engineIoHandler;
    private $middlewareHandler;

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
            $connection->close();
            return;
        }

        if ($method === 'GET') {
            if ($sid === null) {
                $this->handlePollingNew($connection);
            } else {
                $this->handlePollingGet($connection, $sid);
            }
        } elseif ($method === 'POST') {
            $this->handlePollingPost($connection, $sid, $req->rawBody() ?? '');
        } else {
            $this->sendErrorResponse($connection, 'Method not allowed');
        }
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
        $connection->close();

        $session->namespaces['/'] = [];
        
        // 触发连接事件
        $socket = ['nsp' => '/', 'id' => $session->sid];
        $eventData = new \stdClass();
        if ($this->engineIoHandler && method_exists($this->engineIoHandler, 'getEventHandler')) {
            $eventHandler = $this->engineIoHandler->getEventHandler();
            if ($eventHandler) {
                $eventHandler->triggerConnect($socket, '/');
            }
        }

        echo "[connect] polling sid={$session->sid}\n";
    }

    /**
     * 处理获取消息的轮询请求
     */
    private function handlePollingGet(\Workerman\Connection\TcpConnection $connection, string $sid): void
    {
        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}");
            return;
        }

        $messages = $session->flush();
        if (empty($messages)) {
            // 长轮询：延迟10秒再响应
            \Workerman\Timer::add(10, function() use ($connection) {
                $this->sendHttpResponse($connection, 200, [], '6');
            }, [], false);
        } else {
            $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
        }
    }

    /**
     * 处理发送消息的轮询请求
     */
    private function handlePollingPost(\Workerman\Connection\TcpConnection $connection, ?string $sid, string $body): void
    {
        if ($sid === null) {
            $this->sendErrorResponse($connection, 'Missing sid');
            return;
        }

        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}");
            return;
        }

        try {
            $this->processPollingData($session, $body);
            $this->sendHttpResponse($connection, 200, [], 'ok');
        } catch (\Exception $e) {
            $this->sendErrorResponse($connection, $e->getMessage());
        }
        
        $connection->close();
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
        
        foreach ($packets as $packet) {
            if (strlen($packet) === 0) continue;
            
            // 处理单条数据包
            $decodedPacket = PacketParser::parseEngineIOPacket($packet);
            
            if ($decodedPacket) {
                $this->engineIoHandler->handlePacket($packet, $decodedPacket, null, $session);
            }
        }
    }

    /**
     * 发送HTTP响应
     */
    private function sendHttpResponse(\Workerman\Connection\TcpConnection $connection, int $code, array $extraHeaders, string $body, bool $closeConn = true): void
    {
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
            if (isset($corsConfig['origin'])) {
                $defaultHeaders['Access-Control-Allow-Origin'] = $corsConfig['origin'];
            }
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
     * 支持CORs跨域请求
     */
    private function sendCorsResponse(\Workerman\Connection\TcpConnection $connection): void
    {
        $this->sendHttpResponse($connection, 200, [], '');
    }

    /**
     * 检查请求是否正常
     */
    public function validateRequest(array $req): bool
    {
        if (!isset($req['method'])) {
            return false;
        }

        $method = strtoupper($req['method']);
        $validMethods = ['GET', 'POST', 'OPTIONS'];
        
        return in_array($method, $validMethods);
    }
}