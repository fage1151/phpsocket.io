<?php

declare(strict_types=1);

namespace PhpSocketIO\Transport;

use PhpSocketIO\Session;
use PhpSocketIO\Protocol\EngineIOHandler;
use PhpSocketIO\Protocol\PacketParser;
use PhpSocketIO\Support\ServerManager;
use Psr\Log\LoggerInterface;

final class PollingHandler extends AbstractTransportHandler
{
    private EngineIOHandler $engineIoHandler;
    private array $waitingConnections = [];

    public function __construct(ServerManager $serverManager, EngineIOHandler $engineIoHandler)
    {
        parent::__construct($serverManager);
        $this->engineIoHandler = $engineIoHandler;
    }

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
        $session->isPollingUpgrade = true;

        $clientIp = $this->extractClientIp($connection, $req);
        if ($clientIp) {
            $session->setRemoteIp($clientIp);
        }

        $session->setHandshake($this->buildHandshakeData($connection, $req));

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
        if (!Session::validateSidFormat($sid)) {
            $this->sendErrorResponse($connection, 'Invalid session ID format', 400);
            return;
        }

        $session = Session::get($sid);
        if ($session === null) {
            $this->sendErrorResponse($connection, "Session not found: {$sid}", 404);
            return;
        }

        if ($this->isSessionTimeout($session)) {
            Session::remove($sid);
            $this->sendErrorResponse($connection, "Session timeout: {$sid}", 408);
            return;
        }

        $session->lastPong = time();

        $messages = $session->flush();
        if (empty($messages)) {
            if ($session->transport === 'websocket') {
                $this->sendHttpResponse($connection, 200, [], '6');
                return;
            }

            $timeout = $this->calculatePollingTimeout($session);

            $this->waitingConnections[$sid] = [
                'connection' => $connection,
                'timestamp' => time()
            ];

            $timerId = \Workerman\Timer::add($timeout, function () use ($connection, $sid) {
                if (isset($this->waitingConnections[$sid])) {
                    unset($this->waitingConnections[$sid]);
                }

                $session = Session::get($sid);
                if ($session) {
                    $messages = $session->flush();
                    if (!empty($messages)) {                        $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
                        return;
                    }
                }
                $this->sendHttpResponse($connection, 200, [], '6');
            }, [], false);

            ConnectionManager::setTimerId($connection, $timerId);

            $connection->onClose = function () use ($sid, $connection) {
                if (isset($this->waitingConnections[$sid])) {
                    unset($this->waitingConnections[$sid]);
                }
                ConnectionManager::cleanup($connection);
            };

            $this->logger?->debug('Polling connection waiting for messages', [
                'sid' => $sid,
                'timeout' => $timeout
            ]);
        } else {
            $this->sendHttpResponse($connection, 200, [], implode("\x1e", $messages));
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

        $defaultHeaders = array_merge([
            'Connection' => 'close',
            'Content-Type' => 'text/plain; charset=UTF-8'
        ], $this->getCorsHeaders());

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
        $pingInterval = $this->serverManager->getPingInterval();
        $pingTimeout = $this->serverManager->getPingTimeout();
        return (int)(($pingInterval + $pingTimeout) / 1000);
    }

    private function cancelConnectionTimer(\Workerman\Connection\TcpConnection $connection): void
    {
        $timerId = ConnectionManager::getTimerId($connection);
        if ($timerId !== null) {
            \Workerman\Timer::del($timerId);
            ConnectionManager::remove($connection, 'timerId');
        }
    }
}

