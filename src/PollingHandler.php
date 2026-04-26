<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

final class PollingHandler
{
    private ServerManager $serverManager;
    private EngineIOHandler $engineIoHandler;
    private ?LoggerInterface $logger = null;
    private array $waitingConnections = []; // 存储等待的连接

    public function __construct(ServerManager $serverManager, EngineIOHandler $engineIoHandler)
    {
        $this->serverManager = $serverManager;
        $this->engineIoHandler = $engineIoHandler;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 唤醒等待的 polling 连接
     */
    public function wakeWaitingConnection(string $sid): void
    {
        if (!isset($this->waitingConnections[$sid])) {
            return;
        }

        $connectionInfo = $this->waitingConnections[$sid];
        unset($this->waitingConnections[$sid]);

        $connection = $connectionInfo['connection'];
        $this->cancelConnectionTimer($connection);

        $session = Session::get($sid);
        if ($session) {
            $messages = $session->flush();
            if (!empty($messages)) {
                $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
                return;
            }
        }

        $this->sendHttpResponse($connection, 200, [], '6');
    }

    public function handlePolling(\Workerman\Connection\TcpConnection $connection, mixed $req): void
    {
        $method = $req->method();
        $sid = $req->get('sid');

        if ($method === 'OPTIONS') {
            $this->sendHttpResponse($connection, 200, [], '');
            return;
        }

        match ($method) {
            'GET' => $sid === null
                ? $this->handlePollingNew($connection, $req)
                : $this->handlePollingGet($connection, $sid),
            'POST' => $this->handlePollingPost($connection, $sid, $req->rawBody() ?? ''),
            default => $this->sendErrorResponse($connection, 'Method not allowed'),
        };
    }

    private function handlePollingNew(\Workerman\Connection\TcpConnection $connection, mixed $req = null): void
    {
        $session = new Session(Session::generateSid());
        $session->transport = 'polling';
        $session->isPollingUpgrade = true; // 标记这是一个从轮询升级的会话

        // 优先使用 x-real-ip 头
        $clientIp = null;
        if ($req && method_exists($req, 'header')) {
            $xRealIp = $req->header('x-real-ip');
            if ($xRealIp) {
                $clientIp = $xRealIp;
            }
        }

        // 如果没有 x-real-ip，使用 Workerman 原生的 getRemoteIp()
        if (!$clientIp) {
            if (method_exists($connection, 'getRemoteIp')) {
                $clientIp = $connection->getRemoteIp();
            }
        }

        if ($clientIp) {
            $session->setRemoteIp($clientIp);
        }

        $body = '0' . json_encode([
            'sid' => $session->sid,
            'upgrades' => ['websocket'],
            'pingInterval' => $this->serverManager->getPingInterval(),
            'pingTimeout' => $this->serverManager->getPingTimeout(),
            'maxPayload' => 1000000,
            'supportsBinary' => true,
        ]);

        $this->sendHttpResponse($connection, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $body);
    }

    private function handlePollingGet(\Workerman\Connection\TcpConnection $connection, string $sid): void
    {
        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}");
            return;
        }

        if ($this->isSessionTimeout($session)) {
            Session::remove($sid);
            $this->sendErrorResponse($connection, "Session timeout: {$sid}");
            return;
        }

        $session->lastPong = time();

        $messages = $session->flush();
        if (empty($messages)) {
            $timeout = $this->calculatePollingTimeout($session);

            // 保存等待的连接
            $this->waitingConnections[$sid] = [
                'connection' => $connection,
                'timestamp' => time()
            ];

            // 设置超时定时器
            $timerId = \Workerman\Timer::add($timeout, function () use ($connection, $sid) {
                if (isset($this->waitingConnections[$sid])) {
                    unset($this->waitingConnections[$sid]);
                }

                $session = Session::get($sid);
                if ($session) {
                    $messages = $session->flush();
                    if (!empty($messages)) {
                        $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
                        return;
                    }
                }
                $this->sendHttpResponse($connection, 200, [], '6');
            }, [], false);

            $connection->timerId = $timerId;

            // 当连接关闭时，清理等待连接记录
            $connection->onClose = function () use ($sid) {
                if (isset($this->waitingConnections[$sid])) {
                    unset($this->waitingConnections[$sid]);
                }
            };

            $this->logger?->debug('Polling connection waiting for messages', [
                'sid' => $sid,
                'timeout' => $timeout
            ]);
        } else {
            $batchSize = 10;
            $batches = array_chunk($messages, $batchSize);

            foreach ($batches as $batch) {
                $this->sendHttpResponse($connection, 200, [], implode("\x1e", $batch));
            }
        }
    }

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

        $session->lastPong = time();

        try {
            $this->processPollingData($session, $body);
            $this->sendHttpResponse($connection, 200, [], 'ok');
        } catch (\Exception $e) {
            $this->logger?->error('Polling data processing failed', [
                'sid' => $session->sid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendErrorResponse($connection, $e->getMessage());
        }
    }

    private function processPollingData(Session $session, string $data): void
    {
        if (empty($data)) {
            throw new \Exception('Empty data received');
        }

        $packets = explode("\x1e", $data);
        $packetCount = count($packets);

        if ($packetCount > 100) {
            throw new \Exception('Too many packets in single request');
        }

        foreach ($packets as $packet) {
            if (strlen($packet) === 0) {
                continue;
            }

            if (strlen($packet) > 1024 * 1024) {
                throw new \Exception('Packet too large');
            }

            $decodedPacket = PacketParser::parseEngineIOPacket($packet);

            if ($decodedPacket) {
                $this->engineIoHandler->handlePacket($packet, $decodedPacket, null, $session);
            }
        }
    }

    private function sendHttpResponse(
        \Workerman\Connection\TcpConnection $connection,
        int $code,
        array $extraHeaders,
        string $body,
        bool $closeConn = true
    ): void {
        $this->cancelConnectionTimer($connection);

        $defaultHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET,POST,OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
            'Connection' => 'close',
            'Content-Type' => 'text/plain; charset=UTF-8'
        ];

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

    private function sendErrorResponse(\Workerman\Connection\TcpConnection $connection, string $message = 'Error', int $code = 400): void
    {
        $this->sendHttpResponse($connection, $code, [], $message);
    }

    private function isSessionTimeout(Session $session): bool
    {
        $lastPong = property_exists($session, 'lastPong') ? $session->lastPong : 0;
        $timeout = $this->serverManager->getPingTimeout() * 1000;
        return (time() - $lastPong) > ($timeout / 1000);
    }

    private function calculatePollingTimeout(Session $session): int
    {
        return 10;
    }

    private function cancelConnectionTimer(\Workerman\Connection\TcpConnection $connection): void
    {
        if (property_exists($connection, 'timerId')) {
            \Workerman\Timer::del($connection->timerId);
            unset($connection->timerId);
        }
    }
}
