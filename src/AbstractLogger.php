<?php

/*
 * An abstract logger to provide validation of the log level provided.
 */

declare(strict_types = 1);

namespace Programster\Log;

use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
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
     * Open a file without letting fopen()'s PHP warning escape to the application's error handler (which
     * may convert it into an ErrorException). The warning's text is used as the reason in the exception
     * instead.
     * PHP has no native resource type, so this method cannot declare its return type.
     * @param string $filepath - the path to the file to open.
     * @param string $mode - the fopen() mode, e.g. "a" or "r+b".
     * @param string $description - what the file is, for the exception message, e.g. "JSON log file".
     * @return resource - the open file handle.
     * @throws ExceptionFailedToOpenFile - if the file could not be opened.
     */
    protected function openFile(string $filepath, string $mode, string $description)
    {
        $handle = false;

        $reason = $this->runCapturingFailureReason(function () use ($filepath, $mode, &$handle) : void {
            $handle = fopen($filepath, $mode);
        });

        if ($handle === false)
        {
            throw new ExceptionFailedToOpenFile("Failed to open {$description} '{$filepath}' (mode '{$mode}'): {$reason}");
        }

        return $handle;
    }


    /**
     * Run a file operation, capturing any PHP warning it raises rather than letting it reach the application's
     * error handler (which may convert it into an ErrorException). File functions such as fopen() and
     * fwrite() report why they failed through a warning, so its text is returned for use as the reason in an
     * exception message.
     * @param \Closure $operation - the operation to run. It should record its own result by reference.
     * @return string - the text of the last warning raised, or "unknown reason" if there was none.
     */
    protected function runCapturingFailureReason(\Closure $operation) : string
    {
        $reason = "unknown reason";

        set_error_handler(function (int $errno, string $errstr) use (&$reason) : bool {
            $reason = $errstr;
            return true;
        });

        try
        {
            $operation();
        }
        finally
        {
            restore_error_handler();
        }

        return $reason;
    }


    /**
     * Since the PSR3 interface does not specify a type on the log level, this function is responsible
     * for taking that and returning a LogLevel enum that we can work with and know what the variable
     * is.
     * @param mixed $level - a LogLevel enum, a case-insensitive PSR-3 level string (e.g. "info" or "INFO"),
     * or an integer from 0 (debug) to 7 (emergency). Note that this is the reverse of syslog/RFC 5424
     * numbering.
     * @return LogLevel
     * @throws ExceptionInvalidLogLevel - if the level is not valid, or is of an unsupported type.
     */
    protected function convertLogLevelMixedVariable(mixed $level) : LogLevel
    {
        if ($level instanceof LogLevel)
        {
            $result = $level;
        }
        elseif (is_string($level))
        {
            // Levels are case-insensitive, e.g. "INFO" is treated as "info".
            $lowercaseLevel = strtolower($level);
            $this->validateLogLevelString($lowercaseLevel);
            $result = LogLevel::from($lowercaseLevel);
        }
        elseif (is_int($level))
        {
            $this->validateLogLevelInteger($level);
            $result = LogLevel::fromInt($level);
        }
        else
        {
            throw new ExceptionInvalidLogLevel("Unsupported log level type: " . get_debug_type($level));
        }

        return $result;
    }


    /**
     * JSON encode data for a log. Invalid UTF-8 bytes are replaced with the Unicode replacement character
     * (U+FFFD), so that a log is not lost just because it contains some bad bytes. Anything else that cannot
     * be encoded (e.g. INF/NAN, resources, recursive arrays) results in an exception, rather than a log
     * with missing or misleading data.
     * @param array $data - the data to encode.
     * @param string|\Stringable $message - the message being logged, for the exception message.
     * @return string - the JSON.
     * @throws ExceptionFailedToEncodeLog - if the data could not be encoded.
     */
    protected function encodeJson(array $data, string|\Stringable $message) : string
    {
        try
        {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $exception)
        {
            $msg = "Failed to JSON encode log message '{$message}': {$exception->getMessage()}";
            throw new ExceptionFailedToEncodeLog($msg, 0, $exception);
        }

        return $json;
    }
}
