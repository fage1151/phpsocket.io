<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * Socket.IO 房间管理器类 - PHP 8.1+ 深度优化版本
 *
 * @package PhpSocketIO
 */
final class RoomManager
{
    private ?SocketIOServer $server = null;
    private array $rooms = [];
    private array $sessionRooms = [];

    public function setServer(SocketIOServer $server): void
    {
        $this->server = $server;
    }

    public function joinRoom(string $sid, string $room): bool
    {
        $this->rooms[$room] ??= [];
        $this->rooms[$room][$sid] = true;
        $this->sessionRooms[$sid] ??= [];
        $this->sessionRooms[$sid][$room] = true;

        if ($this->server) {
            $adapter = $this->server->getAdapter();
            $adapter?->join($sid, $room);
        }
        return true;
    }

    public function join(string $room, ?Session $session = null): bool
    {
        return $session !== null && $this->joinRoom($session->sid, $room);
    }

    public function leaveRoom(string $sid, string $room): bool
    {
        if (isset($this->rooms[$room])) {
            unset($this->rooms[$room][$sid]);
            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }
        
        if (isset($this->sessionRooms[$sid])) {
            unset($this->sessionRooms[$sid][$room]);
            if (empty($this->sessionRooms[$sid])) {
                unset($this->sessionRooms[$sid]);
            }
        }

        if ($this->server) {
            $adapter = $this->server->getAdapter();
            $adapter?->leave($sid, $room);
        }
        return true;
    }

    public function leave(string $room, ?Session $session = null): bool
    {
        return $session !== null && $this->leaveRoom($session->sid, $room);
    }

    public function leaveAllRooms(string $sid): bool
    {
        if (isset($this->sessionRooms[$sid])) {
            foreach (array_keys($this->sessionRooms[$sid]) as $room) {
                if (isset($this->rooms[$room])) {
                    unset($this->rooms[$room][$sid]);
                    if (empty($this->rooms[$room])) {
                        unset($this->rooms[$room]);
                    }
                }
            }
            unset($this->sessionRooms[$sid]);
        }

        if ($this->server) {
            $adapter = $this->server->getAdapter();
            $adapter?->remove($sid);
        }
        return true;
    }

    public function getRoomMembers(string $room): array
    {
        if ($this->server) {
            $adapter = $this->server->getAdapter();
            if ($adapter) {
                try {
                    return $adapter->clients($room);
                } catch (\Exception $e) {
                    if ($this->server && method_exists($this->server, 'getLogger')) {
                        $this->server->getLogger()?->error('Failed to get room members from adapter', [
                            'room' => $room,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            }
        }
        return array_keys($this->rooms[$room] ?? []);
    }

    public function getSessionRooms(string $sid): array
    {
        return array_keys($this->sessionRooms[$sid] ?? []);
    }

    public function isInRoom(string $sid, string $room): bool
    {
        return isset($this->sessionRooms[$sid][$room]);
    }

    public function roomExists(string $room): bool
    {
        return isset($this->rooms[$room]);
    }

    public function getAllRooms(): array
    {
        return array_keys($this->rooms);
    }

    public function clearAll(): void
    {
        $this->rooms = [];
        $this->sessionRooms = [];
    }

    public function removeSession(string $sid): void
    {
        $this->leaveAllRooms($sid);
    }

    public function getRoomCount(): int
    {
        return count($this->rooms);
    }

    public function getSessionCount(): int
    {
        return count($this->sessionRooms);
    }

    public function joinRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->joinRoom($sid, $room);
        }
        return true;
    }

    public function leaveRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->leaveRoom($sid, $room);
        }
        return true;
    }
}
