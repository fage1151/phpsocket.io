<?php

declare(strict_types=1);

namespace PhpSocketIO\Adapter;

use Psr\Log\LoggerInterface;

/**
 * Socket.IO 跨进程适配器接口
 * 支持跨进程/集群间的Socket.IO消息传递
 */
interface AdapterInterface
{
    /**
     * 初始化适配器
     * @param array $config 配置参数
     */
    public function init(array $config = []): void;

    /**
     * 广播消息到所有进程
     * @param array $packet 消息包数据
     */
    public function broadcast(array $packet): void;

    /**
     * 发送消息到指定房间
     * @param string $room 房间名称
     * @param array $packet 消息包数据
     */
    public function to(string $room, array $packet): void;

    /**
     * 单发消息到指定会话
     * @param string $sid 目标会话ID
     * @param array $packet 消息包数据
     */
    public function emit(string $sid, array $packet): void;

    /**
     * 添加房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function join(string $sid, string $room): void;

    /**
     * 移除房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function leave(string $sid, string $room): void;

    /**
     * 清理会话关联的房间
     * @param string $sid 会话ID
     */
    public function remove(string $sid): void;

    /**
     * 获取房间成员列表
     * @param string $room 房间名称
     * @return array<string> 成员会话ID列表
     */
    public function clients(string $room): array;

    /**
     * 注册会话
     * @param string $sid 会话ID
     */
    public function register(string $sid): void;

    /**
     * 注销会话
     * @param string $sid 会话ID
     */
    public function unregister(string $sid): void;

    /**
     * 关闭适配器
     */
    public function close(): void;

    /**
     * 向集群中其他 Socket.IO 服务器发送消息
     * @param string $eventName 事件名称
     * @param array $args 事件参数
     * @param callable|null $ack 确认回调
     */
    public function serverSideEmit(string $eventName, array $args = [], ?callable $ack = null): void;

    /**
     * 设置日志记录器
     * @param LoggerInterface $logger 日志记录器
     */
    public function setLogger(LoggerInterface $logger): void;
}
