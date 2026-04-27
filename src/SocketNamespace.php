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
    /**
     * 命名空间名称
     *
     * @var string
     */
    private string $name;

    /**
     * Socket.IO 服务器实例
     *
     * @var SocketIOServer
     */
    private SocketIOServer $server;

    /**
     * 构造函数
     *
     * @param string $name 命名空间名称
     * @param SocketIOServer $server Socket.IO 服务器实例
     */
    public function __construct(string $name, SocketIOServer $server)
    {
        $this->name = $name;
        $this->server = $server;
    }

    /**
     * 获取命名空间名称
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 注册事件处理器
     *
     * @param string $event 事件名称
     * @param callable $handler 事件处理器
     * @return self
     */
    public function on(string $event, callable $handler): self
    {
        $this->server->getEventHandler()->on($event, $handler, $this->name);

        // 如果是 connection 事件，还要更新服务器端的 namespaceHandlers
        if ($event === 'connection') {
            $this->server->registerConnectionHandlerForNamespace($this->name, $handler);
        }

        return $this;
    }

    /**
     * 注册命名空间特定的中间件
     *
     * @param callable $middleware 中间件函数
     * @return self
     */
    public function use(callable $middleware): self
    {
        $this->server->getEventHandler()->useForNamespace($this->name, $middleware);
        return $this;
    }

    /**
     * 向命名空间内的所有客户端发送事件
     *
     * @param string $event 事件名称
     * @param mixed ...$args 事件参数
     * @return self
     */
    public function emit(string $event, mixed ...$args): self
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        $broadcaster->emit($event, ...$args);
        return $this;
    }

    /**
     * 向指定房间发送事件
     *
     * @param string|array $room 房间名称或房间数组
     * @return Broadcaster
     */
    public function to(string|array $room): Broadcaster
    {
        $broadcaster = new Broadcaster($this->server, $this->name);
        return $broadcaster->to($room);
    }

    /**
     * to() 方法的别名
     *
     * @param string|array $room 房间名称
     * @return Broadcaster
     */
    public function in(string|array $room): Broadcaster
    {
        return $this->to($room);
    }

    /**
     * 获取命名空间内的所有连接
     *
     * @return array<Socket>
     */
    public function fetchSockets(): array
    {
        return $this->server->fetchSockets($this->name);
    }

    /**
     * 获取服务器实例
     *
     * @return SocketIOServer
     */
    public function getServer(): SocketIOServer
    {
        return $this->server;
    }
}
