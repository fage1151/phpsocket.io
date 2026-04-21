<?php

declare(strict_types=1);

namespace PhpSocketIO;

final class HttpRequestHandler
{
    private ServerManager $serverManager;
    private PollingHandler $pollingHandler;
    private EngineIOHandler $engineIoHandler;

    public function __construct(
        ServerManager $serverManager,
        PollingHandler $pollingHandler,
        EngineIOHandler $engineIoHandler
    ) {
        $this->serverManager = $serverManager;
        $this->pollingHandler = $pollingHandler;
        $this->engineIoHandler = $engineIoHandler;
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

        $isBinary = $this->isBinaryFrame($data);
        if (!$isBinary) {
            $this->processWebSocketData($session, $data);
        } else {
            $binaryData = is_string($data) ? $data : (string)$data;
            $packet = ['type' => 'binary', 'data' => base64_encode($binaryData)];
            $this->engineIoHandler->handlePacket($data, $packet, $connection, $session);
        }
    }

    private function isBinaryFrame(string $data): bool
    {
        if (empty($data)) return false;

        $first = $data[0];

        if ($first === '0' || $first === '1' || $first === '2' ||
            $first === '3' || $first === '4' || $first === '5' ||
            $first === '6' || $first === 'b') {
            return false;
        }

        return true;
    }

    private function processWebSocketData(Session $session, string $data): void
    {
        $this->engineIoHandler->processWebSocketData($session, $data);
    }

    private function isDirectWebSocketHandshake(mixed $req): bool
    {
        return strpos($req->path(), '/socket.io/') !== false
            && $req->get('transport') === 'websocket'
            && !($req->get('sid'));
    }

    private function handleDirectWebSocketHandshake(\Workerman\Connection\TcpConnection $connection, mixed $req): void
    {
        try {
            $sid = Session::generateSid();
            $session = new Session($sid);
            $session->transport = 'websocket';
            $session->isWs = true;
            $session->connection = $connection;

            $this->performWebSocketHandshake($connection, $req, $sid);

            \Workerman\Timer::add(0.1, function() use ($connection, $session) {
                $this->engineIoHandler->sendHandshake($connection, $session);
            }, [], false);

        } catch (\Exception $e) {
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

        if (empty($connection->websocketType)) {
            $connection->websocketType = "\x81";
        }

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
        $isUpgrade = isset($headers['upgrade'])
            && strtolower($headers['upgrade']) === 'websocket';

        $hasKey = isset($headers['sec-websocket-key']);
        $hasVersion = isset($headers['sec-websocket-version']);

        return $isUpgrade && $hasKey && $hasVersion;
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
        $handshakeMessage = "HTTP/1.1 101 Switching Protocol\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $newKey . "\r\n"
            . "Access-Control-Allow-Origin: *\r\n";
        $handshakeMessage .= "\r\n";

        return $handshakeMessage;
    }

    public static function sendWsFrame(\Workerman\Connection\TcpConnection $connection, string $data, bool $isBinary = false): bool
    {
        if (!isset($connection->isWs) || !$connection->isWs) {
            return false;
        }

        try {
            if ($isBinary) {
                $connection->websocketType = "\x82";
                $connection->send($data);
            } else {
                $connection->websocketType = "\x81";
                $connection->send($data);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function isConnectionValid(\Workerman\Connection\TcpConnection $connection): bool
    {
        try {
            $status = $connection->getStatus();
            return $status !== null && $status !== 'closed' && $status !== 'closing';
        } catch (\Exception $e) {
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