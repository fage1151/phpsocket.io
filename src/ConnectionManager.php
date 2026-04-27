<?php

declare(strict_types=1);

namespace PhpSocketIO;

use Workerman\Connection\TcpConnection;

/**
 * 连接管理器
 * 管理连接的附加信息，避免直接在 TcpConnection 上设置动态属性
 * @package PhpSocketIO
 */
final class ConnectionManager
{
    /** @var array<int, array<string, mixed>> 连接附加信息 */
    private static array $connectionInfo = [];

    /**
     * 设置连接信息
     */
    public static function set(TcpConnection $connection, string $key, mixed $value): void
    {
        $id = spl_object_id($connection);
        if (!isset(self::$connectionInfo[$id])) {
            self::$connectionInfo[$id] = [];
        }
        self::$connectionInfo[$id][$key] = $value;
    }

    /**
     * 获取连接信息
     */
    public static function get(TcpConnection $connection, string $key, mixed $default = null): mixed
    {
        $id = spl_object_id($connection);
        return self::$connectionInfo[$id][$key] ?? $default;
    }

    /**
     * 检查连接是否有指定信息
     */
    public static function has(TcpConnection $connection, string $key): bool
    {
        $id = spl_object_id($connection);
        return isset(self::$connectionInfo[$id][$key]);
    }

    /**
     * 移除连接信息
     */
    public static function remove(TcpConnection $connection, string $key): void
    {
        $id = spl_object_id($connection);
        if (isset(self::$connectionInfo[$id][$key])) {
            unset(self::$connectionInfo[$id][$key]);
        }
    }

    /**
     * 清理连接的所有信息
     */
    public static function cleanup(TcpConnection $connection): void
    {
        $id = spl_object_id($connection);
        unset(self::$connectionInfo[$id]);
    }

    /**
     * 获取 SID
     */
    public static function getSid(TcpConnection $connection): ?string
    {
        return self::get($connection, 'sid');
    }

    /**
     * 设置 SID
     */
    public static function setSid(TcpConnection $connection, string $sid): void
    {
        self::set($connection, 'sid', $sid);
    }

    /**
     * 检查是否是 WebSocket 连接
     */
    public static function isWs(TcpConnection $connection): bool
    {
        return (bool)self::get($connection, 'isWs', false);
    }

    /**
     * 设置是否是 WebSocket 连接
     */
    public static function setIsWs(TcpConnection $connection, bool $isWs): void
    {
        self::set($connection, 'isWs', $isWs);
    }

    /**
     * 获取定时器 ID
     */
    public static function getTimerId(TcpConnection $connection): ?int
    {
        return self::get($connection, 'timerId');
    }

    /**
     * 设置定时器 ID
     */
    public static function setTimerId(TcpConnection $connection, int $timerId): void
    {
        self::set($connection, 'timerId', $timerId);
    }
}
