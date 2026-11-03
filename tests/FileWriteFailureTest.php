<?php

/*
 * Tests that the file loggers report write failures (e.g. a full disk) rather than silently losing logs.
 * Uses Linux's /dev/full, which accepts being opened, but fails every write with "No space left on device".
 */

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Programster\Log\CsvFileLogger;
use Programster\Log\Exceptions\ExceptionFailedToWriteFile;
use Programster\Log\FailoverLogger;
use Programster\Log\JsonFileLogger;
use Programster\Log\Tests\Doubles\RecordingLogger;
use Psr\Log\LoggerInterface;

final class FileWriteFailureTest extends TestCase
{
    private const FULL_DEVICE = "/dev/full";


    protected function setUp() : void
    {
        if (!is_writable(self::FULL_DEVICE))
        {
            $this->markTestSkipped(self::FULL_DEVICE . " is not available on this system.");
        }
    }


    /**
     * @return array<string, array{\Closure}> - a factory for each file logger, pointed at the full device.
     */
    public static function provideFileLoggersOnFullDisk() : array
    {
        return [
            'JSON' => [fn() : LoggerInterface => new JsonFileLogger(self::FULL_DEVICE)],
            'CSV' => [fn() : LoggerInterface => new CsvFileLogger(self::FULL_DEVICE)],
        ];
    }


    #[DataProvider('provideFileLoggersOnFullDisk')]
    public function testWriteFailureThrowsWithReason(\Closure $createLogger) : void
    {
        $logger = $createLogger();

        $this->expectException(ExceptionFailedToWriteFile::class);
        $this->expectExceptionMessageMatches("#Failed to write to (JSON|CSV) log file '/dev/full': .*No space left on device#");
        $logger->info("hello");
    }


    #[DataProvider('provideFileLoggersOnFullDisk')]
    public function testWriteFailureDoesNotTriggerPhpWarning(\Closure $createLogger) : void
    {
        $logger = $createLogger();
        $errors = [];

        // Mimic a framework that converts warnings into exceptions, by recording any that reach the app's handler.
        set_error_handler(function (int $errno, string $errstr) use (&$errors) : bool {
            $errors[] = $errstr;
            return true;
        });

        try
        {
            $logger->info("hello");
        }
        catch (ExceptionFailedToWriteFile)
        {
            // Expected.
        }
        finally
        {
            restore_error_handler();
        }

        $this->assertSame([], $errors, "The write warning should not reach the application's error handler.");
    }


    #[DataProvider('provideFileLoggersOnFullDisk')]
    public function testFailoverLoggerFailsOverWhenDiskIsFull(\Closure $createLogger) : void
    {
        $fallback = new RecordingLogger();
        $logger = new FailoverLogger($createLogger(), $fallback);

        $logger->info("hello");

        $logs = $fallback->getLogs();
        $this->assertSame("hello", $logs[0]['message']);
        $this->assertSame(ExceptionFailedToWriteFile::class, $logs[1]['context']['exception_class']);
    }
}
