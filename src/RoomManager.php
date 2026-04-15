<?php

namespace PhpSocketIO;

/**
 * 房间管理器 - 管理Socket.IO的房间功能
 */
class RoomManager
{
    private $rooms = []; // 房间列表，key为房间名，value为Session ID的关联数组（使用关联数组提高查找效率）
    private $sessionRooms = []; // 会话的房间映射，key为Session ID，value为房间名的关联数组

    /**
     * 加入房间
     */
    public function joinRoom(string $sid, string $room): bool
    {
        // 使用关联数组存储房间成员，提高查找效率
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }
        
        if (!isset($this->rooms[$room][$sid])) {
            $this->rooms[$room][$sid] = true;
        }
        
        if (!isset($this->sessionRooms[$sid])) {
            $this->sessionRooms[$sid] = [];
        }
        
        if (!isset($this->sessionRooms[$sid][$room])) {
            $this->sessionRooms[$sid][$room] = true;
        }
        
        return true;
    }

    /**
     * 加入房间（兼容server.php的调用方式）
     * @param string $room 房间名
     * @param Session $session 会话对象
     * @return bool 是否成功
     */
    public function join(string $room, Session $session = null): bool
    {
        if ($session === null) {
            return false;
        }
        return $this->joinRoom($session->sid, $room);
    }

    /**
     * 离开房间
     */
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
        
        return true;
    }

    /**
     * 离开房间（兼容server.php的调用方式）
     * @param string $room 房间名
     * @param Session $session 会话对象
     * @return bool 是否成功
     */
    public function leave(string $room, Session $session = null): bool
    {
        if ($session === null) {
            return false;
        }
        return $this->leaveRoom($session->sid, $room);
    }

    /**
     * 离开所有房间
     */
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
        
        return true;
    }

    /**
     * 获取房间成员列表
     */
    public function getRoomMembers(string $room): array
    {
        return array_keys($this->rooms[$room] ?? []);
    }

    /**
     * 获取会话所在的房间
     */
    public function getSessionRooms(string $sid): array
    {
        return array_keys($this->sessionRooms[$sid] ?? []);
    }

    /**
     * 检查会话是否在房间中
     */
    public function isInRoom(string $sid, string $room): bool
    {
        return isset($this->sessionRooms[$sid][$room]);
    }

    /**
     * 检查房间是否存在
     */
    public function roomExists(string $room): bool
    {
        return isset($this->rooms[$room]);
    }

    /**
     * 获取所有房间列表
     */
    public function getAllRooms(): array
    {
        return array_keys($this->rooms);
    }

    /**
     * 清空所有房间数据
     */
    public function clearAll(): void
    {
        $this->rooms = [];
        $this->sessionRooms = [];
    }

    /**
     * 删除会话数据
     */
    public function removeSession(string $sid): void
    {
        $this->leaveAllRooms($sid);
    }

    /**
     * 获取房间数量
     */
    public function getRoomCount(): int
    {
        return count($this->rooms);
    }

    /**
     * 获取会话数量
     */
    public function getSessionCount(): int
    {
        return count($this->sessionRooms);
    }

    /**
     * 批量加入多个房间
     */
    public function joinRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->joinRoom($sid, $room);
        }
        return true;
    }

    /**
     * 批量离开多个房间
     */
    public function leaveRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->leaveRoom($sid, $room);
        }
        return true;
    }
}