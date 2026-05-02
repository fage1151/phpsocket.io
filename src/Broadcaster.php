<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Psr\Log\LoggerInterface;

/**
 * Socket.IO 广播器类 (不可变链式调用版本)
 *
 * 提供事件广播功能，支持房间、命名空间等
 * 符合 Socket.IO v4 规范：每次链式调用返回新实例
 *
 * @package PhpSocketIO
 */
final class Broadcaster
{
    private ?SocketIOServer $server;
    private string $namespace;
    private ?Socket $excludeSocket;
    private array $targetRooms = [];
    private array $exceptRooms = [];
    private bool $volatile = false;
    private bool $compress = false;
    private ?int $timeout = null;
    private bool $local = false;
    private ?LoggerInterface $logger = null;

    public function __construct(
        ?SocketIOServer $server = null,
        string $namespace = '/',
        ?Socket $excludeSocket = null,
        array $targetRooms = [],
        array $exceptRooms = [],
        bool $volatile = false,
        bool $compress = false,
        ?int $timeout = null,
        bool $local = false
    ) {
        $this->server = $server;
        $this->namespace = $namespace;
        $this->excludeSocket = $excludeSocket;
        $this->targetRooms = $targetRooms;
        $this->exceptRooms = $exceptRooms;
        $this->volatile = $volatile;
        $this->compress = $compress;
        $this->timeout = $timeout;
        $this->local = $local;

        if ($this->server && method_exists($this->server, 'getLogger')) {
            $this->logger = $this->server->getLogger();
        }
    }

    public function to(string|array $room): self
    {
        $rooms = is_array($room) ? $room : [$room];
        $newTargetRooms = array_merge($this->targetRooms, $rooms);
        $newTargetRooms = array_unique($newTargetRooms);

        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $newTargetRooms,
            $this->exceptRooms,
            $this->volatile,
            $this->compress,
            $this->timeout,
            $this->local
        );
    }

    public function in(string|array $room): self
    {
        return $this->to($room);
    }

    public function except(string|array $room): self
    {
        $rooms = is_array($room) ? $room : [$room];
        $newExceptRooms = array_merge($this->exceptRooms, $rooms);
        $newExceptRooms = array_unique($newExceptRooms);

        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $this->targetRooms,
            $newExceptRooms,
            $this->volatile,
            $this->compress,
            $this->timeout,
            $this->local
        );
    }

    public function broadcast(): self
    {
        return $this;
    }

    public function local(): self
    {
        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $this->targetRooms,
            $this->exceptRooms,
            $this->volatile,
            $this->compress,
            $this->timeout,
            true
        );
    }

    public function volatile(): self
    {
        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $this->targetRooms,
            $this->exceptRooms,
            true,
            $this->compress,
            $this->timeout,
            $this->local
        );
    }

    public function compress(bool $compress = true): self
    {
        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $this->targetRooms,
            $this->exceptRooms,
            $this->volatile,
            $compress,
            $this->timeout,
            $this->local
        );
    }

    public function timeout(int $timeout): self
    {
        return new self(
            $this->server,
            $this->namespace,
            $this->excludeSocket,
            $this->targetRooms,
            $this->exceptRooms,
            $this->volatile,
            $this->compress,
            $timeout,
            $this->local
        );
    }

    public function emit(string $event, mixed ...$args): ?SocketIOServer
    {
        if (!$this->server) {
            $this->logger?->warning('Broadcaster未关联服务器，无法发送事件', ['event' => $event]);
            return null;
        }

        try {
            if (!empty($this->targetRooms)) {
                $this->emitToRooms($event, $args);
            } else {
                $this->emitToAll($event, $args);
            }
            return $this->server;
        } catch (\Exception $e) {
            $this->logger?->error('Broadcaster发送事件失败', [
                'event' => $event,
                'namespace' => $this->namespace,
                'targetRooms' => $this->targetRooms,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function buildEventPacket(string $event, array $args): array
    {
        $packetStr = PacketParser::buildSocketIOPacket('EVENT', [
            'namespace' => $this->namespace,
            'event' => $event,
            'data' => $args
        ]);
        return PacketParser::parseSocketIOPacket($packetStr);
    }

    private function emitToRooms(string $event, array $args): void
    {
        $adapter = $this->server->getAdapter();

        if ($adapter) {
            $packetArray = $this->buildEventPacket($event, $args);
            foreach ($this->targetRooms as $room) {
                $adapter->to($room, $packetArray);
            }
            return;
        }

        $roomManager = $this->server->getRoomManager();
        foreach ($this->targetRooms as $room) {
            $members = $roomManager->getRoomMembers($room);
            foreach ($members as $sid) {
                if (!$this->shouldExclude($sid)) {
                    $this->emitToSocket($sid, $event, $args);
                }
            }
        }
    }

    private function emitToAll(string $event, array $args): void
    {
        $adapter = $this->server->getAdapter();
        if ($adapter) {
            $packetArray = $this->buildEventPacket($event, $args);
            $adapter->broadcast($packetArray);
            return;
        }

        $sockets = $this->server->fetchSockets($this->namespace);
        foreach ($sockets as $socket) {
            if ($this->shouldExclude($socket->sid)) {
                continue;
            }
            if ($this->isInExceptRoom($socket)) {
                continue;
            }
            $socket->emit($event, ...$args);
        }
    }

    private function emitToSocket(string $sid, string $event, array $args): void
    {
        $session = Session::get($sid);
        if (!$session) {
            return;
        }
        $socket = $this->server->getOrCreateSocket($session, $this->namespace);
        $socket->emit($event, ...$args);
    }

    private function shouldExclude(string $sid): bool
    {
        return $this->excludeSocket && $this->excludeSocket->sid === $sid;
    }

    private function isInExceptRoom(Socket $socket): bool
    {
        foreach ($this->exceptRooms as $room) {
            if ($socket->inRoom($room)) {
                return true;
            }
        }
        return false;
    }
}
