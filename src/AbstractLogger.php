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
}
