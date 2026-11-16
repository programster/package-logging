<?php

/*
 * How long a FailoverLogger skips a logger for after it fails, so that logs go straight to the next logger, rather
 * than waiting on the broken one (e.g. a database connection timing out) every time.
 *
 * Create one with createUsingSeconds(), createDefault() for the default of 3 seconds, or createNone() to try every
 * logger on every log.
 */

declare(strict_types = 1);
namespace Programster\Log;


use Programster\Log\Exceptions\ExceptionInvalidRetryDelay;

final readonly class RetryDelay
{
    private const DEFAULT_SECONDS = 3.0;

    private float $m_seconds;
    private bool $m_retrySkippedLoggersIfAllFail;


    private function __construct(float $seconds, bool $retrySkippedLoggersIfAllFail)
    {
        $this->m_seconds = $seconds;
        $this->m_retrySkippedLoggersIfAllFail = $retrySkippedLoggersIfAllFail;
    }


    /**
     * Create a retry delay of a number of seconds: a logger that fails is skipped for this long.
     *
     * @param float $seconds - how long to skip a logger for after it fails, e.g. 3. Fractions of a second are
     * allowed. It must be more than 0: use createNone() for no delay.
     *
     * @param bool $retrySkippedLoggersIfAllFail - what to do when every logger that isn't being skipped fails (or
     * every logger is being skipped). If false (the default), ExceptionAllLoggersFailed is thrown straight away. If
     * true, the skipped loggers are then tried too, in order, as a last resort, so that a logger that has recovered
     * isn't passed over. This is off by default because a broken logger can take a long time to fail (e.g. waiting
     * for a connection to time out), so trying them all again can hold up the application for a long time.
     *
     * @return RetryDelay
     *
     * @throws ExceptionInvalidRetryDelay - if $seconds is not a finite number of seconds more than 0.
     */
    public static function createUsingSeconds(float $seconds, bool $retrySkippedLoggersIfAllFail = false) : RetryDelay
    {
        if (!is_finite($seconds) || $seconds <= 0)
        {
            // var_export() rather than interpolation, as PHP 8.5 warns when NAN is converted to a string.
            $given = var_export($seconds, true);

            $msg = "The retry delay must be a finite number of seconds more than 0, but {$given} was given. Use "
                . "RetryDelay::createNone() for no retry delay.";

            throw new ExceptionInvalidRetryDelay($msg);
        }

        return new RetryDelay($seconds, $retrySkippedLoggersIfAllFail);
    }


    /**
     * Create the retry delay a FailoverLogger has unless it's given another: 3 seconds, without retrying the skipped
     * loggers if all the others fail.
     * @return RetryDelay
     */
    public static function createDefault() : RetryDelay
    {
        return new RetryDelay(self::DEFAULT_SECONDS, false);
    }


    /**
     * Create no retry delay, so that every logger is tried on every log, and a logger that failed is tried again on
     * the very next log.
     * @return RetryDelay
     */
    public static function createNone() : RetryDelay
    {
        return new RetryDelay(0.0, false);
    }


    # Accessors
    public function getSeconds() : float { return $this->m_seconds; }
    public function shouldRetrySkippedLoggersIfAllFail() : bool { return $this->m_retrySkippedLoggersIfAllFail; }
}
