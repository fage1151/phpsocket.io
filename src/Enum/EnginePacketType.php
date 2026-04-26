<?php

declare(strict_types=1);

namespace PhpSocketIO\Enum;

/**
 * Engine.IO 数据包类型枚举
 *
 * @package PhpSocketIO\Enum
 */
enum EnginePacketType: int
{
    case OPEN = 0;
    case CLOSE = 1;
    case PING = 2;
    case PONG = 3;
    case MESSAGE = 4;
    case UPGRADE = 5;
    case NOOP = 6;

    public static function tryFromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}
