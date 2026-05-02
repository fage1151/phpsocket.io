<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

/**
 * Socket.IO 命名空间类
 *
 * 提供标准的 Socket.IO 命名空间接口
 *
 * @package PhpSocketIO
 */
final class SocketNamespace
{
    private string $name;
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

    public function emit(string $event, mixed ...$args): self
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        $broadcaster->emit($event, ...$args);
        return $this;
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

    public function fetchSockets(): array
    {
        return $this->server->fetchSockets($this->name);
    }

    public function allSockets(): Set
    {
        return $this->server->allSockets($this->name);
    }

    public function socketsJoin(string|array $rooms): self
    {
        $this->server->socketsJoin($rooms, $this->name);
        return $this;
    }

    public function socketsLeave(string|array $rooms): self
    {
        $this->server->socketsLeave($rooms, $this->name);
        return $this;
    }

    public function getAdapter(): ?Adapter\AdapterInterface
    {
        return $this->server->getAdapter();
    }

    public function getServer(): SocketIOServer
    {
        return $this->server;
    }
}
