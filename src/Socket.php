<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;
use Workerman\Connection\TcpConnection;

/**
 * Socket.IO Socket 类 (完整 Socket.IO v4 规范实现)
 *
 * 代表客户端连接
 *
 * @package PhpSocketIO
 */
class Socket
{
    public ?string $sid;
    public string $namespace;
    public ?SocketIOServer $server;
    public ?TcpConnection $connection;
    public ?Session $session;
    public mixed $auth;
    public mixed $handshake;
    public mixed $headers;
    public array $data = [];
    public ?SocketConn $conn = null;

    private ?LoggerInterface $logger = null;
    private array $middlewares = [];
    private array $onceHandlers = [];
    private ?SocketNamespace $nspInstance = null;
    private array $anyListeners = [];
    private array $anyOutgoingListeners = [];
    public bool $recovered = false;

    public function __construct(
        ?string $sid = null,
        string $namespace = '/',
        ?SocketIOServer $server = null,
        ?TcpConnection $connection = null,
        ?Session $session = null
    ) {
        $this->sid = $sid;
        $this->namespace = $namespace;
        $this->server = $server;
        $this->connection = $connection;

        if ($session) {
            $this->session = $session;
        } else {
            $this->session = Session::get($sid);
        }

        if ($this->server && method_exists($this->server, 'getLogger')) {
            $this->logger = $this->server->getLogger();
        }

        if ($this->session) {
            $this->handshake = $this->session->handshake;
            $this->data = &$this->session->data;
            $this->conn = new SocketConn($this->session);
        }

        if ($this->server) {
            $this->nspInstance = $this->server->of($this->namespace);
        }
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->sid,
            'rooms' => $this->getRooms(),
            'broadcast' => $this->getBroadcast(),
            'connected' => $this->isConnected(),
            'disconnected' => !$this->isConnected(),
            'nsp' => $this->nspInstance,
            'request' => $this->getRequest(),
            'recovered' => $this->recovered,
            default => null,
        };
    }

    private function getRequest(): mixed
    {
        if ($this->session && isset($this->session->request)) {
            return $this->session->request;
        }
        return null;
    }

    public function getRooms(): Set
    {
        $rooms = [];
        if ($this->session && $this->sid && $this->server) {
            $rooms = $this->server->getRoomManager()->getSessionRooms($this->sid);
        }
        if ($this->sid && !in_array($this->sid, $rooms)) {
            array_unshift($rooms, $this->sid);
        }
        return new Set($rooms);
    }

    public function getBroadcast(): Broadcaster
    {
        return new Broadcaster($this->server, $this->namespace, $this);
    }

    public function isConnected(): bool
    {
        return $this->session !== null && $this->session->upgraded;
    }

    public function emit(string $event, mixed ...$args): self
    {
        if (empty($event)) {
            $this->logger?->error('事件名称不能为空');
            throw new \InvalidArgumentException('事件名称不能为空');
        }

        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $event)) {
            $this->logger?->error('事件名称格式无效', ['event' => $event]);
            throw new \InvalidArgumentException('事件名称格式无效');
        }

        if (strlen($event) > 128) {
            $this->logger?->error('事件名称过长', ['event_length' => strlen($event)]);
            throw new \InvalidArgumentException('事件名称过长，最大128字符');
        }

        $reservedEvents = ['connect', 'disconnect', 'disconnecting', 'newListener', 'removeListener'];
        if (in_array(strtolower($event), $reservedEvents, true)) {
            $this->logger?->warning('使用保留事件名称', ['event' => $event]);
        }

        try {
            return $this->hasBinaryData($args)
                ? $this->emitBinary($event, ...$args)
                : $this->sendStandardEvent($event, ...$args);
        } catch (\Exception $e) {
            $this->logger?->error('发送事件失败', [
                'event' => $event,
                'sid' => $this->sid,
                'namespace' => $this->namespace,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function emitBinary(string $event, mixed ...$args): self
    {
        if (!$this->session) {
            return $this;
        }

        foreach ($this->anyOutgoingListeners as $listener) {
            try {
                $listener($event, ...$args);
            } catch (\Exception $e) {
                $this->logger?->error('Catch-all outgoing listener error', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $binaryAttachments = [];
        $replacedArgs = $this->replaceBinaryWithPlaceholders($args, $binaryAttachments);
        $binaryCount = count($binaryAttachments);

        $packet = PacketParser::buildSocketIOPacket('BINARY_EVENT', [
            'namespace' => $this->namespace,
            'event' => $event,
            'data' => $replacedArgs,
            'binaryCount' => $binaryCount,
        ]);
        $this->session->send($packet);

        foreach ($binaryAttachments as $attachment) {
            $this->session->sendBinary($attachment);
        }

        return $this;
    }

    private function replaceBinaryWithPlaceholders(array $data, array &$attachments): array
    {
        $result = [];
        foreach ($data as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $this->replaceBinaryWithPlaceholders($item, $attachments);
            } elseif ($this->isBinaryString($item) || is_resource($item)) {
                $attachments[] = $item;
                $result[$key] = ['_placeholder' => true, 'num' => count($attachments) - 1];
            } else {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    private function sendStandardEvent(string $event, mixed ...$args): self
    {
        if (!$this->session) {
            return $this;
        }

        foreach ($this->anyOutgoingListeners as $listener) {
            try {
                $listener($event, ...$args);
            } catch (\Exception $e) {
                $this->logger?->error('Catch-all outgoing listener error', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $packet = PacketParser::buildSocketIOPacket('EVENT', [
            'namespace' => $this->namespace,
            'event' => $event,
            'data' => $args,
        ]);
        $this->session->send($packet);
        return $this;
    }

    public function emitWithAck(string $event, mixed ...$args): void
    {
        $callback = array_pop($args);
        if (!is_callable($callback)) {
            return;
        }

        if (!$this->session) {
            return;
        }

        $ackId = $this->session->ackIdCounter++;
        $packet = PacketParser::buildSocketIOPacket('ACK', [
            'namespace' => $this->namespace,
            'event' => $event,
            'data' => $args,
            'id' => $ackId,
        ]);

        $this->session->ackCallbacks[$ackId] = $callback;
        $this->session->send($packet);
    }

    public function join(string|array $room): self
    {
        if (!$this->session || !$this->server) {
            return $this;
        }

        $rooms = is_array($room) ? $room : [$room];
        foreach ($rooms as $r) {
            $this->server->getRoomManager()->joinRoom($this->sid, $r);
        }

        return $this;
    }

    public function leave(string $room): self
    {
        if (!$this->session || !$this->server) {
            return $this;
        }

        $this->server->getRoomManager()->leaveRoom($this->sid, $room);
        return $this;
    }

    public function to(string|array $room): Broadcaster
    {
        return $this->getBroadcast()->to($room);
    }

    public function in(string|array $room): Broadcaster
    {
        return $this->to($room);
    }

    public function except(string|array $rooms): Broadcaster
    {
        return $this->getBroadcast()->except($rooms);
    }

    public function volatile(): Broadcaster
    {
        return $this->getBroadcast()->volatile();
    }

    public function compress(bool $compress = true): Broadcaster
    {
        return $this->getBroadcast()->compress($compress);
    }

    public function timeout(int $timeout): Broadcaster
    {
        return $this->getBroadcast()->timeout($timeout);
    }

    public function disconnect(bool $close = false): self
    {
        if (!$this->session) {
            return $this;
        }

        if ($this->server) {
            $socketContext = [
                'id' => $this->sid,
                'session' => $this->session,
                'connection' => $this->connection,
                'namespace' => $this->namespace,
                'socket' => $this,
            ];
            $this->server->getEventHandler()->triggerDisconnecting($socketContext, 'server namespace disconnect');
        }

        if ($this->server && $this->sid) {
            $this->server->getRoomManager()->leaveAllRooms($this->sid);
        }

        if ($close) {
            $this->session->close();
        } else {
            $disconnectPacket = PacketParser::buildSocketIOPacket('DISCONNECT', [
                'namespace' => $this->namespace,
            ]);
            $this->session->send($disconnectPacket);
        }

        if ($this->server) {
            $socketContext = [
                'id' => $this->sid,
                'session' => $this->session,
                'connection' => $this->connection,
                'namespace' => $this->namespace,
                'socket' => $this,
            ];
            $this->server->getEventHandler()->triggerDisconnect($socketContext, 'server namespace disconnect');
        }

        return $this;
    }

    public function inRoom(string $room): bool
    {
        if (!$this->session || !$this->server) {
            return false;
        }
        return $this->server->getRoomManager()->isInRoom($this->sid, $room);
    }

    public function on(string $event, callable $callback): self
    {
        if (!$this->server) {
            throw new \RuntimeException('Socket实例未关联到服务器');
        }

        $this->server->getEventHandler()->on($event, $callback, $this->namespace);

        return $this;
    }

    public function once(string $event, callable $callback): self
    {
        if (!$this->server) {
            throw new \RuntimeException('Socket实例未关联到服务器');
        }

        $onceHandler = new class ($event, $callback, $this) {
            public string $event;
            /** @var callable */
            public $callback;
            private Socket $socket;
            /** @var callable */
            public $wrappedCallback;

            public function __construct(string $event, callable $callback, Socket $socket)
            {
                $this->event = $event;
                $this->callback = $callback;
                $this->socket = $socket;

                $self = $this;
                $this->wrappedCallback = static function (mixed ...$args) use ($self): void {
                    $self->socket->off($self->event, $self->wrappedCallback);
                    call_user_func_array($self->callback, $args);
                };
            }
        };

        $this->onceHandlers[$event][] = $onceHandler;
        $this->server->getEventHandler()->on($event, $onceHandler->wrappedCallback, $this->namespace);

        return $this;
    }

    public function off(string $event, ?callable $callback = null): self
    {
        if (!$this->server) {
            return $this;
        }

        $eventHandler = $this->server->getEventHandler();

        if ($callback === null) {
            $eventHandler->removeEventHandler($this->namespace, $event);
            unset($this->onceHandlers[$event]);
        } else {
            $eventHandler->removeEventHandler($this->namespace, $event, $callback);

            foreach ($this->onceHandlers[$event] ?? [] as $index => $onceCb) {
                if ($onceCb->callback === $callback || $onceCb->wrappedCallback === $callback) {
                    $eventHandler->removeEventHandler($this->namespace, $event, $onceCb->wrappedCallback);
                    unset($this->onceHandlers[$event][$index]);
                }
            }
        }

        return $this;
    }

    public function removeAllListeners(?string $event = null): self
    {
        if (!$this->server) {
            return $this;
        }

        $eventHandler = $this->server->getEventHandler();

        if ($event !== null) {
            $eventHandler->removeEventHandler($this->namespace, $event);
            unset($this->onceHandlers[$event]);
        } else {
            $allHandlers = $eventHandler->getAllEventHandlers($this->namespace);
            foreach (array_keys($allHandlers) as $eventName) {
                $eventHandler->removeEventHandler($this->namespace, $eventName);
            }
            $this->onceHandlers = [];
        }

        return $this;
    }

    public function listeners(string $event): array
    {
        if (!$this->server) {
            return [];
        }

        $handler = $this->server->getEventHandler()->getEventHandler($this->namespace, $event);
        return $handler !== null ? [$handler] : [];
    }

    public function hasListeners(string $event): bool
    {
        if (!$this->server) {
            return false;
        }

        return $this->server->getEventHandler()->hasEventHandler($this->namespace, $event);
    }

    public function use(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function runMiddlewares(array $packet, callable $next): void
    {
        $index = 0;
        $middlewareCount = count($this->middlewares);

        $nextMiddleware = function () use (&$index, $middlewareCount, $packet, $next, &$nextMiddleware): void {
            if ($index < $middlewareCount) {
                $middleware = $this->middlewares[$index];
                $index++;
                $middleware($packet, $nextMiddleware);
            } else {
                $next();
            }
        };

        $nextMiddleware();
    }

    private function hasBinaryData(array $data): bool
    {
        foreach ($data as $item) {
            if (is_array($item)) {
                if ($this->hasBinaryData($item)) {
                    return true;
                }
            } elseif ($item instanceof \Closure || is_resource($item) || $this->isBinaryString($item)) {
                return true;
            }
        }
        return false;
    }

    private function isBinaryString(mixed $item): bool
    {
        if (!is_string($item)) {
            return false;
        }
        return preg_match('/[^\x20-\x7E\t\r\n]/', $item) === 1;
    }

    public function send(mixed ...$args): self
    {
        $callback = null;
        if (!empty($args) && is_callable(end($args))) {
            $callback = array_pop($args);
        }

        if ($callback !== null) {
            array_unshift($args, 'message');
            $args[] = $callback;
            call_user_func_array([$this, 'emit'], $args);
        } else {
            $this->emit('message', ...$args);
        }

        return $this;
    }

    public function onAny(callable $callback): self
    {
        $this->anyListeners[] = $callback;
        return $this;
    }

    public function onAnyOutgoing(callable $callback): self
    {
        $this->anyOutgoingListeners[] = $callback;
        return $this;
    }

    public function prependAny(callable $callback): self
    {
        array_unshift($this->anyListeners, $callback);
        return $this;
    }

    public function prependAnyOutgoing(callable $callback): self
    {
        array_unshift($this->anyOutgoingListeners, $callback);
        return $this;
    }

    public function offAny(?callable $callback = null): self
    {
        if ($callback === null) {
            $this->anyListeners = [];
        } else {
            $index = array_search($callback, $this->anyListeners, true);
            if ($index !== false) {
                unset($this->anyListeners[$index]);
                $this->anyListeners = array_values($this->anyListeners);
            }
        }
        return $this;
    }

    public function offAnyOutgoing(?callable $callback = null): self
    {
        if ($callback === null) {
            $this->anyOutgoingListeners = [];
        } else {
            $index = array_search($callback, $this->anyOutgoingListeners, true);
            if ($index !== false) {
                unset($this->anyOutgoingListeners[$index]);
                $this->anyOutgoingListeners = array_values($this->anyOutgoingListeners);
            }
        }
        return $this;
    }

    public function listenersAny(): array
    {
        return $this->anyListeners;
    }

    public function listenersAnyOutgoing(): array
    {
        return $this->anyOutgoingListeners;
    }
}
