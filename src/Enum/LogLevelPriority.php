<?php

declare(strict_types=1);

namespace PhpSocketIO\Enum;

/**
 * 日志级别优先级枚举
 *
 * @package PhpSocketIO\Enum
 */
enum LogLevelPriority: int
{
    case DEBUG = 0;
    case INFO = 1;
    case NOTICE = 2;
    case WARNING = 3;
    case ERROR = 4;
    case CRITICAL = 5;
    case ALERT = 6;
    case EMERGENCY = 7;

    public static function fromName(string $level): self
    {
        return match (strtolower($level)) {
            'debug' => self::DEBUG,
            'info' => self::INFO,
            'notice' => self::NOTICE,
            'warning' => self::WARNING,
            'error' => self::ERROR,
            'critical' => self::CRITICAL,
            'alert' => self::ALERT,
            'emergency' => self::EMERGENCY,
            default => self::INFO,
        };
    }

    public static function shouldLog(string $level, self $currentLevel): bool
    {
        return self::fromName($level)->value >= $currentLevel->value;
    }
}
