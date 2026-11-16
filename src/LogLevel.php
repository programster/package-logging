<?php

/*
 * The PSR-3 log levels, with a number for each, from 0 (debug) to 7 (emergency), so they can be compared.
 * Note that the numbers are the reverse of syslog/RFC 5424 numbering.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionInvalidLogLevel;

enum LogLevel: string
{
    case EMERGENCY = "emergency";
    case ALERT     = "alert";
    case CRITICAL  = "critical";
    case ERROR     = "error";
    case WARNING   = "warning";
    case NOTICE    = "notice";
    case INFO      = "info";
    case DEBUG     = "debug";


    /**
     * Get the level for a number, from 0 (debug) to 7 (emergency).
     * @param int $level - the number of the level.
     * @return LogLevel
     * @throws ExceptionInvalidLogLevel - if the number isn't from 0 to 7.
     */
    public static function createFromInt(int $level) : LogLevel
    {
        $invalidMessage = "Invalid log level value of '{$level}' provided. Value needs to be between 0 and 7";

        return match ($level) {
            7 => LogLevel::EMERGENCY,
            6 => LogLevel::ALERT,
            5 => LogLevel::CRITICAL,
            4 => LogLevel::ERROR,
            3 => LogLevel::WARNING,
            2 => LogLevel::NOTICE,
            1 => LogLevel::INFO,
            0 => LogLevel::DEBUG,
            default => throw new ExceptionInvalidLogLevel($invalidMessage),
        };
    }


    /**
     * Get the level for a PSR-3 level string, ignoring case, e.g. "warning" or "WARNING".
     * @param string $level - the level string.
     * @return LogLevel
     * @throws ExceptionInvalidLogLevel - if it isn't one of the PSR-3 levels.
     */
    public static function createFromString(string $level) : LogLevel
    {
        $result = LogLevel::tryFrom(strtolower($level));

        if ($result === null)
        {
            $validLevels = implode(", ", array_column(LogLevel::cases(), 'value'));
            $msg = "Invalid log level value of '{$level}' provided. Value needs to be one of {$validLevels}";
            throw new ExceptionInvalidLogLevel($msg);
        }

        return $result;
    }


    /**
     * Get the number of the level, from 0 (debug) to 7 (emergency), so that levels can be compared.
     * @return int
     */
    public function toInt() : int
    {
        return match ($this) {
            self::EMERGENCY => 7,
            self::ALERT     => 6,
            self::CRITICAL  => 5,
            self::ERROR     => 4,
            self::WARNING   => 3,
            self::NOTICE    => 2,
            self::INFO      => 1,
            self::DEBUG     => 0,
        };
    }
}
