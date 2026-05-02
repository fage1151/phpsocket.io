<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

use PhpSocketIO\Protocol\PacketParser;
use PhpSocketIO\Event\EventHandler;
use PhpSocketIO\Support\Set;
use PhpSocketIO\Broadcaster;
use PhpSocketIO\Socket;
use PhpSocketIO\Session;

/**
 * Socket.IO 命名空间类 (完整 Socket.IO v4 规范)
 *
 * @package PhpSocketIO
 */
final class SocketNamespace
{
    public readonly string $name;
    private SocketIOServer $server;

    public function __construct(string $name, SocketIOServer $server)
    {
        $this->name = $name;
        $this->server = $server;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAdapter(): ?Adapter\AdapterInterface
    {
        return $this->server->getAdapter();
    }

    public function getServer(): SocketIOServer
    {
        return $this->server;
    }

    public function on(string $event, callable $handler): self
    {
        $this->server->getEventHandler()->on($event, $handler, $this->name);

        if ($event === 'connection') {
            $this->server->registerConnectionHandlerForNamespace($this->name, $handler);
        }

        return $this;
    }

    public function off(string $event, ?callable $handler = null): self
    {
        $this->server->getEventHandler()->removeEventHandler($this->name, $event, $handler);
        return $this;
    }

    public function use(callable $middleware): self
    {
        $this->server->getEventHandler()->useForNamespace($this->name, $middleware);
        return $this;
    }

    public function emit(string $event, mixed ...$args): bool
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->emit($event, ...$args);
    }

    public function to(string|array $room): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->to($room);
    }

    public function in(string|array $room): Broadcaster
    {
        return $this->to($room);
    }

    public function except(string|array $rooms): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->except($rooms);
    }

    public function volatile(): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->volatile();
    }

    public function compress(bool $compress = true): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->compress($compress);
    }

    public function timeout(int $timeout): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->timeout($timeout);
    }

    public function fetchSockets(): array
    {
        return $this->server->fetchSockets($this->name);
    }

    public function allSockets(): Set
    {
        return $this->server->allSockets($this->name);
    }

    public function socketsJoin(string|array $rooms): void
    {
        $this->server->socketsJoin($rooms, $this->name);
    }

    public function socketsLeave(string|array $rooms): void
    {
        $this->server->socketsLeave($rooms, $this->name);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'adapter' => $this->getAdapter(),
            'sockets' => $this->getSocketsMap(),
            'name' => $this->name,
            default => null,
        };
    }

    public function getSocketsMap(): array
    {
        $sockets = [];
        $activeSessions = Session::all();

        foreach ($activeSessions as $sid => $session) {
            if (isset($session->namespaces[$this->name]) && $session->namespaces[$this->name]) {
                $socket = $this->server->getOrCreateSocket($session, $this->name);
                $sockets[$sid] = $socket;
            }
        }

        return $sockets;
    }

    public function getConnectedCount(): int
    {
        $count = 0;
        foreach (Session::all() as $session) {
            if (isset($session->namespaces[$this->name]) && $session->namespaces[$this->name]) {
                $count++;
            }
        }
        return $count;
    }

    public function disconnectSockets(bool $close = false): void
    {
        $sockets = $this->fetchSockets();
        foreach ($sockets as $socket) {
            $socket->disconnect($close);
        }
    }

    public function emitWithAck(string $event, mixed ...$args): array
    {
        $sockets = $this->fetchSockets();
        $responses = [];

        foreach ($sockets as $socket) {
            $socket->emitWithAck($event, ...$args);
            $responses[] = null;
        }

        return $responses;
    }

    public function local(): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->local();
    }

    public function serverSideEmit(string $event, mixed ...$args): void
    {
        $adapter = $this->server->getAdapter();
        if ($adapter && method_exists($adapter, 'serverSideEmit')) {
            $adapter->serverSideEmit($event, $args);
            return;
        }

        $this->server->getLogger()->debug('serverSideEmit (single process)', [
            'namespace' => $this->name,
            'event' => $event,
            'args_count' => count($args)
        ]);
    }
}
