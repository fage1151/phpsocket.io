<?php

declare(strict_types=1);

namespace PhpSocketIO;

/**
 * Socket.IO 广播器类
 *
 * 提供事件广播功能，支持房间、命名空间等
 *
 * @package PhpSocketIO
 */
final class Broadcaster
{
    /**
     * Socket.IO服务器实例
     *
     * @var SocketIOServer|null
     */
    private ?SocketIOServer $server;

    /**
     * 命名空间
     *
     * @var string
     */
    private string $namespace;

    /**
     * 目标房间
     *
     * @var string|array|null
     */
    private string|array|null $targetRoom = null;

    /**
     * 要排除的Socket
     *
     * @var Socket|null
     */
    private ?Socket $excludeSocket = null;

    /**
     * 要排除的房间
     *
     * @var array
     */
    private array $exceptRooms = [];

    /**
     * 构造函数
     *
     * @param SocketIOServer|null $server 服务器实例
     * @param string $namespace 命名空间
     * @param Socket|null $excludeSocket 要排除的Socket
     */
    public function __construct(
        ?SocketIOServer $server = null,
        string $namespace = '/',
        ?Socket $excludeSocket = null
    ) {
        $this->server = $server;
        $this->namespace = $namespace;
        $this->excludeSocket = $excludeSocket;
    }

    /**
     * 设置目标房间
     *
     * @param string|array $room 房间名或房间数组
     * @return self
     */
    public function to(string|array $room): self
    {
        $this->targetRoom = $room;
        return $this;
    }

    /**
     * 设置排除房间
     *
     * @param string|array $room 房间名或房间数组
     * @return self
     */
    public function except(string|array $room): self
    {
        if (is_array($room)) {
            $this->exceptRooms = array_merge($this->exceptRooms, $room);
        } else {
            $this->exceptRooms[] = $room;
        }
        return $this;
    }

    /**
     * 启用广播
     *
     * @return self
     */
    public function broadcast(): self
    {
        return $this;
    }

    /**
     * 发送事件
     *
     * @param string $event 事件名
     * @param mixed ...$args 事件参数
     * @return SocketIOServer|null
     */
    public function emit(string $event, mixed ...$args): ?SocketIOServer
    {
        if (!$this->server) {
            return null;
        }
        if ($this->targetRoom) {
            $this->emitToRooms($event, $args);
        } else {
            $this->emitToAll($event, $args);
        }
        return $this->server;
    }

    /**
     * 构建Socket.IO数据包（优化版，复用构建结果）
     */
    private function buildEventPacket(string $event, array $args): array
    {
        $packetStr = PacketParser::buildSocketIOPacket('EVENT', [
            'namespace' => $this->namespace,
            'event' => $event,
            'data' => $args
        ]);
        return PacketParser::parseSocketIOPacket($packetStr);
    }

    /**
     * 向指定房间发送事件
     *
     * @param string $event 事件名
     * @param array $args 事件参数
     * @return void
     */
    private function emitToRooms(string $event, array $args): void
    {
        $adapter = $this->server->getAdapter();
        $rooms = is_array($this->targetRoom) ? $this->targetRoom : [$this->targetRoom];
        
        if ($adapter) {
            // 使用适配器：只构建一次数据包
            $packetArray = $this->buildEventPacket($event, $args);
            foreach ($rooms as $room) {
                $adapter->to($room, $packetArray);
            }
            return;
        }
        
        // 不使用适配器：逐个发送
        $roomManager = $this->server->getRoomManager();
        foreach ($rooms as $room) {
            $members = $roomManager->getRoomMembers($room);
            foreach ($members as $sid) {
                $this->emitToSocket($sid, $event, $args);
            }
        }
    }

    /**
     * 向所有客户端发送事件
     *
     * @param string $event 事件名
     * @param array $args 事件参数
     * @return void
     */
    private function emitToAll(string $event, array $args): void
    {
        $adapter = $this->server->getAdapter();
        if ($adapter) {
            // 使用适配器：只构建一次数据包
            $packetArray = $this->buildEventPacket($event, $args);
            $adapter->broadcast($packetArray);
            return;
        }
        
        // 不使用适配器：逐个发送
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

    /**
     * 向指定Socket发送事件
     *
     * @param string $sid SocketID
     * @param string $event 事件名
     * @param array $args 事件参数
     * @return void
     */
    private function emitToSocket(string $sid, string $event, array $args): void
    {
        if ($this->shouldExclude($sid)) {
            return;
        }
        $session = Session::get($sid);
        if (!$session) {
            return;
        }
        // 直接通过session发送，避免创建新Socket实例
        $socket = new Socket($sid, $this->namespace, $this->server);
        $socket->session = $session;
        $socket->emit($event, ...$args);
    }

    /**
     * 检查是否应该排除指定Socket
     *
     * @param string $sid SocketID
     * @return bool
     */
    private function shouldExclude(string $sid): bool
    {
        return $this->excludeSocket && $this->excludeSocket->sid === $sid;
    }

    /**
     * 检查Socket是否在排除房间中
     *
     * @param Socket $socket Socket实例
     * @return bool
     */
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
