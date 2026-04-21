<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * Socket.IO 房间管理器类
 *
 * 管理房间成员关系和房间操作
 *
 * @package PhpSocketIO
 */
final class RoomManager
{
    /**
     * 房间成员映射
     *
     * @var array
     */
    private array $rooms = [];

    /**
     * 会话房间映射
     *
     * @var array
     */
    private array $sessionRooms = [];

    /**
     * 将会话加入房间
     *
     * @param string $sid 会话ID
     * @param string $room 房间名
     * @return bool
     */
    public function joinRoom(string $sid, string $room): bool
    {
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
     * 将会话加入房间
     *
     * @param string $room 房间名
     * @param Session|null $session 会话实例
     * @return bool
     */
    public function join(string $room, ?Session $session = null): bool
    {
        if ($session === null) {
            return false;
        }
        return $this->joinRoom($session->sid, $room);
    }

    /**
     * 将会话离开房间
     *
     * @param string $sid 会话ID
     * @param string $room 房间名
     * @return bool
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
     * 将与会话离开房间
     *
     * @param string $room 房间名
     * @param Session|null $session 会话实例
     * @return bool
     */
    public function leave(string $room, ?Session $session = null): bool
    {
        if ($session === null) {
            return false;
        }
        return $this->leaveRoom($session->sid, $room);
    }

    /**
     * 将会话从所有房间中移除
     *
     * @param string $sid 会话ID
     * @return bool
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
     *
     * @param string $room 房间名
     * @return array
     */
    public function getRoomMembers(string $room): array
    {
        return array_keys($this->rooms[$room] ?? []);
    }

    /**
     * 获取会话所在的房间列表
     *
     * @param string $sid 会话ID
     * @return array
     */
    public function getSessionRooms(string $sid): array
    {
        return array_keys($this->sessionRooms[$sid] ?? []);
    }

    /**
     * 检查会话是否在指定房间中
     *
     * @param string $sid 会话ID
     * @param string $room 房间名
     * @return bool
     */
    public function isInRoom(string $sid, string $room): bool
    {
        return isset($this->sessionRooms[$sid][$room]);
    }

    /**
     * 检查房间是否存在
     *
     * @param string $room 房间名
     * @return bool
     */
    public function roomExists(string $room): bool
    {
        return isset($this->rooms[$room]);
    }

    /**
     * 获取所有房间列表
     *
     * @return array
     */
    public function getAllRooms(): array
    {
        return array_keys($this->rooms);
    }

    /**
     * 清空所有房间数据
     *
     * @return void
     */
    public function clearAll(): void
    {
        $this->rooms = [];
        $this->sessionRooms = [];
    }

    /**
     * 移除会话的所有房间
     *
     * @param string $sid 会话ID
     * @return void
     */
    public function removeSession(string $sid): void
    {
        $this->leaveAllRooms($sid);
    }

    /**
     * 获取房间数量
     *
     * @return int
     */
    public function getRoomCount(): int
    {
        return count($this->rooms);
    }

    /**
     * 获取会话数量
     *
     * @return int
     */
    public function getSessionCount(): int
    {
        return count($this->sessionRooms);
    }

    /**
     * 将会话加入多个房间
     *
     * @param string $sid 会话ID
     * @param array $rooms 房间列表
     * @return bool
     */
    public function joinRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->joinRoom($sid, $room);
        }
        return true;
    }

    /**
     * 将会话离开多个房间
     *
     * @param string $sid 会话ID
     * @param array $rooms 房间列表
     * @return bool
     */
    public function leaveRooms(string $sid, array $rooms): bool
    {
        foreach ($rooms as $room) {
            $this->leaveRoom($sid, $room);
        }
        return true;
    }
}
