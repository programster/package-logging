<?php

/*
 * An abstract logger to provid validation of the log level provided.
 */

declare(strict_types = 1);

namespace Programster\Log;

abstract class AbstractLogger extends \Psr\Log\AbstractLogger
{
    protected function validateLogLevel($level)
    {
        $validLogLeels = array(
            \Psr\Log\LogLevel::EMERGENCY,
            \Psr\Log\LogLevel:: ALERT,
            \Psr\Log\LogLevel:: CRITICAL,
            \Psr\Log\LogLevel:: ERROR,
            \Psr\Log\LogLevel:: WARNING,
            \Psr\Log\LogLevel:: NOTICE,
            \Psr\Log\LogLevel:: INFO,
            \Psr\Log\LogLevel:: DEBUG,
        );

        if (!in_array($level, $validLogLeels))
        {
            $msg = "Invalid log level value of '{$level}' provided. Value needs to be one of " . implode(", ", $validLogLeels);
            throw new \Exception($msg);
        }
    }


    /**
     * Converts a string log level to an integer value
     * @param string $level - one of debug, error, info etc.
     * @return int - the priority level. E.g. debug is 0, and emergency is 7
     * @throws \Exception
     */
    protected function convertLogLevelToInteger(string $level) : int
    {
        switch ($level)
        {
            case \Psr\Log\LogLevel::EMERGENCY: return 7;
            case \Psr\Log\LogLevel::ALERT: return 6;
            case \Psr\Log\LogLevel::CRITICAL: return 5;
            case \Psr\Log\LogLevel::ERROR: return 4;
            case \Psr\Log\LogLevel::WARNING: return 3;
            case \Psr\Log\LogLevel::NOTICE: return 2;
            case \Psr\Log\LogLevel::INFO: return 1;
            case \Psr\Log\LogLevel::DEBUG: return 0;
            default: throw new \Exception("Unrecognized log level: {$level}");
        }
    }
}
