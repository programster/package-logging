<?php

/*
 * An abstract logger to provide validation of the log level provided.
 */

declare(strict_types = 1);

namespace Programster\Log;

use Programster\Log\Exceptions\ExceptionInvalidLogLevel;

abstract class AbstractLogger extends \Psr\Log\AbstractLogger
{
    /**
     * @param string $level
     * @return void
     * @throws ExceptionInvalidLogLevel - if an invalid level was provided.
     */
    protected function validateLogLevelString(string $level) : void
    {
        $validLogLevels = array(
            \Psr\Log\LogLevel::EMERGENCY,
            \Psr\Log\LogLevel::ALERT,
            \Psr\Log\LogLevel::CRITICAL,
            \Psr\Log\LogLevel::ERROR,
            \Psr\Log\LogLevel::WARNING,
            \Psr\Log\LogLevel::NOTICE,
            \Psr\Log\LogLevel::INFO,
            \Psr\Log\LogLevel::DEBUG,
        );

        if (!in_array($level, $validLogLevels))
        {
            $msg = "Invalid log level value of '{$level}' provided. Value needs to be one of " . implode(", ", $validLogLevels);
            throw new ExceptionInvalidLogLevel($msg);
        }
    }


    /**
     * Validates that the log level integer provided is valid.
     * @param int $level
     * @return void
     * @throws ExceptionInvalidLogLevel - if an invalid log level integer was provided.
     */
    protected function validateLogLevelInteger(int $level) : void
    {
        if ($level < 0 || $level > 7)
        {
            $msg = "Invalid log level value of '{$level}' provided. Value needs to be between 0 and 7";
            throw new ExceptionInvalidLogLevel($msg);
        }
    }


    protected function convertStringLogLevelToEnum(string $level) : LogLevel
    {
        return LogLevel::from($level);
    }


    /**
     * Since the PSR3 interface does not specify a type on the log level, this function is responseible
     * for taking that and returning a LogLevel enum that we can work with and know what the variable
     * is.
     * @param string|int|LogLevel $level
     * @return LogLevel
     * @throws ExceptionInvalidLogLevel
     */
    protected function convertLogLevelMixedVariable(string|int|LogLevel $level) : LogLevel
    {
        if (is_string($level))
        {
            $this->validateLogLevelString($level);
            return LogLevel::from($level);
        }
        elseif (is_int($level))
        {
            $this->validateLogLevelInteger($level);
            return LogLevel::fromInt($level);
        }
        elseif(is_a($level, LogLevel::class))
        {
            return $level;
        }
        else
        {
            throw new ExceptionInvalidLogLevel("Unexpected log level type: " . print_r($level));
        }
    }
}
