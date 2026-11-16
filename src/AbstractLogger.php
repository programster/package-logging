<?php

/*
 * The base class of the package's loggers, with the helpers they share: converting the log level that PSR-3 lets be
 * anything into a LogLevel, opening and writing files without letting PHP's warnings reach the application, and
 * JSON encoding a log.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;

abstract class AbstractLogger extends \Psr\Log\AbstractLogger
{
    /**
     * Since the PSR-3 interface does not specify a type for the log level, this takes whatever was given, and
     * returns the LogLevel it stands for.
     *
     * @param mixed $level - a LogLevel enum, a case-insensitive PSR-3 level string (e.g. "info" or "INFO"), or an
     * integer from 0 (debug) to 7 (emergency). Note that this is the reverse of syslog/RFC 5424 numbering.
     *
     * @return LogLevel
     *
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
            $result = LogLevel::createFromString($level);
        }
        elseif (is_int($level))
        {
            $result = LogLevel::createFromInt($level);
        }
        else
        {
            throw new ExceptionInvalidLogLevel("Unsupported log level type: " . get_debug_type($level));
        }

        return $result;
    }


    /**
     * Open a file without letting fopen()'s PHP warning escape to the application's error handler (which may convert
     * it into an ErrorException). The warning's text is used as the reason in the exception instead.
     *
     * @param string $filepath - the path to the file to open.
     *
     * @param string $mode - the fopen() mode, e.g. "a" or "r+b".
     *
     * @param string $description - what the file is, for the exception message, e.g. "JSON log file".
     *
     * @return resource - the open file handle. PHP has no native resource type, so this can't be declared.
     *
     * @throws ExceptionFailedToOpenFile - if the file could not be opened.
     */
    protected function openFile(string $filepath, string $mode, string $description)
    {
        $handle = false;

        $openFile = function () use ($filepath, $mode, &$handle) : void {
            $handle = fopen($filepath, $mode);
        };

        $reason = $this->runCapturingFailureReason($openFile);

        if ($handle === false)
        {
            $msg = "Failed to open {$description} '{$filepath}' (mode '{$mode}'): {$reason}";
            throw new ExceptionFailedToOpenFile($msg);
        }

        return $handle;
    }


    /**
     * Run a file operation, capturing any PHP warning it raises rather than letting it reach the application's error
     * handler (which may convert it into an ErrorException). File functions such as fopen() and fwrite() report why
     * they failed through a warning, so its text is returned for use as the reason in an exception message.
     *
     * @param \Closure $operation - the operation to run. It should record its own result by reference.
     *
     * @return string - the text of the last warning raised, or "unknown reason" if there was none.
     */
    protected function runCapturingFailureReason(\Closure $operation) : string
    {
        $reason = "unknown reason";

        $recordReason = function (int $errno, string $errstr) use (&$reason) : bool {
            $reason = $errstr;
            return true;
        };

        set_error_handler($recordReason);

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
     * JSON encode data for a log. Invalid UTF-8 bytes are replaced with the Unicode replacement character (U+FFFD),
     * so that a log is not lost just because it contains some bad bytes. Anything else that cannot be encoded (e.g.
     * INF/NAN, resources, recursive arrays) results in an exception, rather than a log with missing or misleading
     * data.
     *
     * @param array<mixed> $data - the data to encode.
     *
     * @param string|\Stringable $message - the message being logged, for the exception message.
     *
     * @return string - the JSON.
     *
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
