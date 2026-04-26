<?php

declare(strict_types=1);

namespace PhpSocketIO\Enum;

/**
 * Socket.IO 数据包类型枚举
 *
 * @package PhpSocketIO\Enum
 */
enum SocketPacketType: int
{
    case CONNECT = 0;
    case DISCONNECT = 1;
    case EVENT = 2;
    case ACK = 3;
    case CONNECT_ERROR = 4;
    case BINARY_EVENT = 5;
    case BINARY_ACK = 6;

    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}
