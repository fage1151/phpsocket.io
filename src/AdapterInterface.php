<?php

namespace PhpSocketIO;

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
    public function init(array $config): void;
    
    /**
     * 发布消息到指定房间
     * @param string $room 房间名称
     * @param array $packet 消息包数据
     */
    public function publish(string $room, array $packet): void;
    
    /**
     * 订阅房间消息
     * @param string $room 房间名称
     * @param callable $callback 消息回调函数
     */
    public function subscribe(string $room, callable $callback): void;
    
    /**
     * 取消订阅房间
     * @param string $room 房间名称
     */
    public function unsubscribe(string $room): void;
    
    /**
     * 广播消息到所有进程
     * @param array $packet 消息包数据
     */
    public function broadcast(array $packet): void;
    
    /**
     * 单发消息到指定会话（仅会话在当前进程时消费，否则转发到其他进程）
     * @param string $sid 目标会话ID
     * @param array $packet 消息包数据
     */
    public function send(string $sid, array $packet): void;
    
    /**
     * 添加房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function add(string $sid, string $room): void;
    
    /**
     * 删除房间成员
     * @param string $sid 会话ID
     * @param string $room 房间名称
     */
    public function del(string $sid, string $room): void;
    
    /**
     * 清理会话关联的房间
     * @param string $sid 会话ID
     */
    public function delAll(string $sid): void;
    
    /**
     * 获取房间成员列表
     * @param string $room 房间名称
     * @return array 成员会话ID列表
     */
    public function clients(string $room): array;
    
    /**
     * 获取会话所在的房间列表
     * @param string $sid 会话ID
     * @return array 房间名称列表
     */
    public function rooms(string $sid): array;
    
    /**
     * 关闭适配器连接
     */
    public function close(): void;
}