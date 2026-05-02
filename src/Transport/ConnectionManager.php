<?php

declare(strict_types=1);

namespace PhpSocketIO\Transport;

use Workerman\Connection\TcpConnection;

/**
 * 连接管理器
 * 管理连接的附加信息，避免直接在 TcpConnection 上设置动态属性
 * @package PhpSocketIO\Transport
 */
final class ConnectionManager
{
    private static array $connectionInfo = [];

    public static function set(TcpConnection $connection, string $key, mixed $value): void
    {
        $id = spl_object_id($connection);
        if (!isset(self::$connectionInfo[$id])) {
            self::$connectionInfo[$id] = [];
        }
        self::$connectionInfo[$id][$key] = $value;
    }

    public static function get(TcpConnection $connection, string $key, mixed $default = null): mixed
    {
        $id = spl_object_id($connection);
        return self::$connectionInfo[$id][$key] ?? $default;
    }

    public static function has(TcpConnection $connection, string $key): bool
    {
        $id = spl_object_id($connection);
        return isset(self::$connectionInfo[$id][$key]);
    }

    public static function remove(TcpConnection $connection, string $key): void
    {
        $id = spl_object_id($connection);
        if (isset(self::$connectionInfo[$id][$key])) {
            unset(self::$connectionInfo[$id][$key]);
        }
    }

    public static function cleanup(TcpConnection $connection): void
    {
        $id = spl_object_id($connection);
        unset(self::$connectionInfo[$id]);
    }

    public static function getSid(TcpConnection $connection): ?string
    {
        return self::get($connection, 'sid');
    }

    public static function setSid(TcpConnection $connection, string $sid): void
    {
        self::set($connection, 'sid', $sid);
    }

    public static function isWs(TcpConnection $connection): bool
    {
        return (bool)self::get($connection, 'isWs', false);
    }

    public static function setIsWs(TcpConnection $connection, bool $isWs): void
    {
        self::set($connection, 'isWs', $isWs);
    }

    public static function getTimerId(TcpConnection $connection): ?int
    {
        return self::get($connection, 'timerId');
    }

    public static function setTimerId(TcpConnection $connection, int $timerId): void
    {
        self::set($connection, 'timerId', $timerId);
    }
}
