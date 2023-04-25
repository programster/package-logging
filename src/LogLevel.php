<?php

namespace Programster\Log;

enum LogLevel: string
{
    case EMERGENCY = "emergency";
    case ALERT     = "alert";
    case CRITICAL  = "critical";
    case ERROR     = "error";
    case WARNING   = "warning";
    case NOTICE    = "notice";
    case INFO      = "info";
    case DEBUG     = "debug"; // 0


    public function toInt(): int
    {
        return match($this) {
            self::EMERGENCY => 7,
            self::ALERT     => 6,
            self::CRITICAL  => 5,
            self::ERROR     => 4,
            self::WARNING   => 3,
            self::NOTICE    => 2,
            self::INFO      => 1,
            self::DEBUG     => 0
        };
    }


    public static function fromInt(int $level): LogLevel
    {
        return match($level) {
            7 => LogLevel::EMERGENCY,
            6 => LogLevel::ALERT,
            5 => LogLevel::CRITICAL,
            4 => LogLevel::ERROR,
            3 => LogLevel::WARNING,
            2 => LogLevel::NOTICE,
            1 => LogLevel::INFO,
            0 => LogLevel::DEBUG,
        };
    }
}
