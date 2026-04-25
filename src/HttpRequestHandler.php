<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

/**
 * Socket.IO HTTP 请求处理器
 * 处理 WebSocket 握手、轮询请求等
 * @package PhpSocketIO
 */
final class HttpRequestHandler
{
    private ServerManager $serverManager;
    private PollingHandler $pollingHandler;
    private EngineIOHandler $engineIoHandler;
    private ?LoggerInterface $logger = null;
    
    /** @var array<int, string> 有效的引擎IO数据包起始字符 */
    private const VALID_PACKET_CHARS = ['0', '1', '2', '3', '4', '5', '6', 'b'];

    public function __construct(
        ServerManager $serverManager,
        PollingHandler $pollingHandler,
        EngineIOHandler $engineIoHandler
    ) {
        $this->serverManager = $serverManager;
        $this->pollingHandler = $pollingHandler;
        $this->engineIoHandler = $engineIoHandler;
    }
    
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function handleMessage(\Workerman\Connection\TcpConnection $connection, mixed $req): void
    {
        if (!isset($connection->isWs) && $this->isDirectWebSocketHandshake($req)) {
            $this->handleDirectWebSocketHandshake($connection, $req);
            return;
        }

        if (isset($connection->isWs) && $connection->isWs) {
            $this->handleWebSocketMessage($connection, $req);
            return;
        }

        if ($this->isWebSocketUpgradeRequest($req)) {
            $this->handleUpgrade($connection, $req);
            return;
        }

        $this->pollingHandler->handlePolling($connection, $req);
    }

    private function handleWebSocketMessage(\Workerman\Connection\TcpConnection $connection, mixed $data): void
    {
        if (!isset($connection->sid)) {
            $connection->close();
            return;
        }

        $session = Session::get($connection->sid);
        if (!$session) {
            return;
        }

        if (!$session->connection || !method_exists($session->connection, 'send')) {
            $session->connection = $connection;
            $session->isWs = true;
            $session->transport = 'websocket';
        }

        if (empty($data)) {
            return;
        }

        $isBinary = $this->isBinaryFrame((string)$data);
        if (!$isBinary) {
            $this->processWebSocketData($session, (string)$data);
        } else {
            $binaryData = is_string($data) ? $data : (string)$data;
            $packet = ['type' => 'binary', 'data' => base64_encode($binaryData)];
            $this->engineIoHandler->handlePacket($data, $packet, $connection, $session);
        }
    }

    private function isBinaryFrame(string $data): bool
    {
        return !empty($data) && !in_array($data[0], self::VALID_PACKET_CHARS, true);
    }

    private function processWebSocketData(Session $session, string $data): void
    {
        $this->engineIoHandler->processWebSocketData($session, $data);
    }

    private function isDirectWebSocketHandshake(mixed $req): bool
    {
        return str_starts_with($req->path(), '/socket.io/')
            && $req->get('transport') === 'websocket'
            && !$req->get('sid');
    }

    private function handleDirectWebSocketHandshake(\Workerman\Connection\TcpConnection $connection, mixed $req): void
    {
        try {
            $sid = Session::generateSid();
            $session = new Session($sid);
            $session->transport = 'websocket';
            $session->isWs = true;
            $session->connection = $connection;
            $session->isPollingUpgrade = false; // 这是直接的 WebSocket 连接，不是从轮询升级来的
            $session->upgraded = true; // 直接标记为升级完成

            $this->performWebSocketHandshake($connection, $req, $sid);

            \Workerman\Timer::add(0.1, function() use ($connection, $session) {
                $this->engineIoHandler->sendHandshake($connection, $session);
            }, [], false);

        } catch (\Exception $e) {
            $this->logger?->error('WebSocket handshake failed', [
                'remote_address' => $connection->getRemoteAddress(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $connection->close();
        }
    }

    private function performWebSocketHandshake(
        \Workerman\Connection\TcpConnection $connection,
        mixed $req,
        string $sid
    ): void {
        $handshakeResponse = $this->generateWebSocketHandshakeResponse(
            $req->header('sec-websocket-key')
        );
        $connection->send($handshakeResponse, true);

        $connection->websocketType ??= "\x81";
        $connection->protocol = \Workerman\Protocols\Websocket::class;
        $connection->context->websocketHandshake = true;
        $connection->context->websocketDataBuffer = '';
        $connection->context->websocketCurrentFrameLength = 0;
        $connection->context->websocketCurrentFrameBuffer = '';
        $connection->sid = $sid;
        $connection->isWs = true;
    }

    private function isWebSocketUpgradeRequest(mixed $req): bool
    {
        $headers = $req->header();
        return isset($headers['upgrade'], $headers['sec-websocket-key'], $headers['sec-websocket-version'])
            && strtolower($headers['upgrade']) === 'websocket';
    }

    private function handleUpgrade(\Workerman\Connection\TcpConnection $connection, mixed $req): void
    {
        $connection->isWs = true;
        $this->upgradeToWebSocket($connection, $req);
    }

    public function upgradeToWebSocket(\Workerman\Connection\TcpConnection $connection, mixed $req): bool
    {
        $sid = $req->get('sid');
        if (!$sid) {
            return false;
        }

        $session = Session::get($sid);
        if (!$session) {
            return false;
        }

        $connection->isWs = true;
        $connection->sid = $sid;
        $session->connection = $connection;
        $session->transport = 'websocket';
        $session->isWs = true;

        $this->performWebSocketHandshake($connection, $req, $sid);

        $messages = $session->flush();
        foreach ($messages as $msg) {
            $session->send($msg);
        }

        return true;
    }

    private function generateWebSocketHandshakeResponse(string $key): string
    {
        $newKey = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        return "HTTP/1.1 101 Switching Protocol\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $newKey . "\r\n"
            . "Access-Control-Allow-Origin: *\r\n\r\n";
    }

    public function sendWsFrame(\Workerman\Connection\TcpConnection $connection, string $data, bool $isBinary = false): bool
    {
        if (!isset($connection->isWs) || !$connection->isWs) {
            return false;
        }

        try {
            $connection->websocketType = $isBinary ? "\x82" : "\x81";
            $connection->send($data);
            return true;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send WebSocket frame', [
                'sid' => $connection->sid ?? 'unknown',
                'is_binary' => $isBinary,
                'data_length' => strlen($data),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function isConnectionValid(\Workerman\Connection\TcpConnection $connection): bool
    {
        try {
            $status = $connection->getStatus();
            return $status !== null && $status !== 'closed' && $status !== 'closing';
        } catch (\Exception $e) {
            $this->logger?->debug('Failed to check connection status', [
                'remote_address' => $connection->getRemoteAddress(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function handleConnectionClose(\Workerman\Connection\TcpConnection $connection): void
    {
        if (isset($connection->sid)) {
            $sid = $connection->sid;
            $session = Session::get($sid);
            if ($session) {
                Session::remove($sid);
            }
        }
    }
}
