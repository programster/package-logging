<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionInvalidJsonLogFile;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;
use Programster\Log\JsonFileLogger;

final class JsonFileLoggerTest extends TestCase
{
    private string $m_tempDir;


    protected function setUp() : void
    {
        $this->m_tempDir = sys_get_temp_dir() . "/json-file-logger-test-" . bin2hex(random_bytes(8));
        mkdir($this->m_tempDir);
    }


    protected function tearDown() : void
    {
        foreach (glob("{$this->m_tempDir}/*") as $file)
        {
            unlink($file);
        }

        rmdir($this->m_tempDir);
    }


    public function testLogsAreWrittenAsValidJsonArray() : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $logger->info("first", ['key' => 'value']);
        $logger->error("second");

        $logs = $this->readLogs($filepath);
        $this->assertCount(2, $logs);
        $this->assertSame(['info', 'first', ['key' => 'value']], [$logs[0]['level'], $logs[0]['message'], $logs[0]['context']]);
        $this->assertSame(['error', 'second', []], [$logs[1]['level'], $logs[1]['message'], $logs[1]['context']]);
    }


    #[DataProvider('provideExistingJsonArrays')]
    public function testAppendsToExistingJsonArrayInAnyFormatting(string $existingContent, array $expectedMessages) : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        file_put_contents($filepath, $existingContent);

        $logger = new JsonFileLogger($filepath);
        $logger->info("new");
        $logger->info("newer");

        $messages = array_column($this->readLogs($filepath), 'message');
        $this->assertSame([...$expectedMessages, "new", "newer"], $messages);
    }


    public static function provideExistingJsonArrays() : array
    {
        return [
            'empty file' => ["", []],
            'whitespace only' => ["  \n\n", []],
            'empty array' => ["[]", []],
            'empty array with whitespace' => ["[ \n ]\n", []],
            'this logger\'s format' => ["[\n{\"message\":\"old1\"},\n{\"message\":\"old2\"}\n]\n", ["old1", "old2"]],
            'no trailing newline' => ["[\n{\"message\":\"old1\"},\n{\"message\":\"old2\"}\n]", ["old1", "old2"]],
            'single line' => ['[{"message":"old1"},{"message":"old2"}]', ["old1", "old2"]],
            'pretty printed' => ["[\n    {\n        \"message\": \"old1\"\n    }\n]\n\n", ["old1"]],
            'windows line endings' => ["[\r\n{\"message\":\"old1\"}\r\n]\r\n", ["old1"]],
        ];
    }


    #[DataProvider('provideNonJsonArrayContent')]
    public function testRefusesToModifyFileThatIsNotAJsonArray(string $existingContent) : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        file_put_contents($filepath, $existingContent);
        $logger = new JsonFileLogger($filepath);
        $thrown = null;

        try
        {
            $logger->info("new");
        }
        catch (ExceptionInvalidJsonLogFile $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionInvalidJsonLogFile to be thrown.");
        $this->assertStringContainsString($filepath, $thrown->getMessage());
        $this->assertSame($existingContent, file_get_contents($filepath), "The file must be left untouched.");
    }


    public static function provideNonJsonArrayContent() : array
    {
        return [
            'plain text' => ["some log line\n"],
            'JSON object' => ['{"message":"old"}'],
            'truncated array' => ["[\n{\"message\":\"old1\"},\n{\"message\":\"ol"],
            'only an opening bracket' => ["["],
            'only a closing bracket' => ["]"],
        ];
    }


    public function testRecreatesFileIfRemovedWhilstRunning() : void
    {
        // E.g. logrotate moving the file away.
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $logger->info("before");
        rename($filepath, "{$filepath}.1");

        $logger->info("after");

        $this->assertSame(["after"], array_column($this->readLogs($filepath), 'message'));
        $this->assertSame(["before"], array_column($this->readLogs("{$filepath}.1"), 'message'));
    }


    #[DataProvider('provideValidLevels')]
    public function testAcceptsEveryLevelFormatAndWritesTheLevelString(mixed $level, string $expectedLevel) : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $logger->log($level, "hello");

        $this->assertSame($expectedLevel, $this->readLogs($filepath)[0]['level']);
    }


    #[DataProvider('provideInvalidLevels')]
    public function testRejectsInvalidLevelsWithPsr3ExceptionAndWritesNothing(mixed $level) : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $thrown = null;

        try
        {
            $logger->log($level, "hello");
        }
        catch (ExceptionInvalidLogLevel $exception)
        {
            $thrown = $exception;
        }

        $this->assertInstanceOf(\Psr\Log\InvalidArgumentException::class, $thrown, "PSR-3 requires this exception type.");
        $this->assertSame("", file_get_contents($filepath));
    }


    public static function provideValidLevels() : array
    {
        return LevelProviders::validLevels();
    }


    public static function provideInvalidLevels() : array
    {
        return LevelProviders::invalidLevels();
    }


    public function testInvalidUtf8IsReplacedRatherThanLosingTheLog() : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $logger->info("bad \xB1 message", ['key' => "bad \xB1 context"]);

        $logs = $this->readLogs($filepath);
        $this->assertSame("bad \u{FFFD} message", $logs[0]['message']);
        $this->assertSame("bad \u{FFFD} context", $logs[0]['context']['key']);
    }


    public function testStringableMessageIsLoggedAsItsString() : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $message = new class implements \Stringable {
            public string $notTheMessage = "property";

            public function __toString() : string
            {
                return "the message";
            }
        };

        $logger = new JsonFileLogger($filepath);
        $logger->info($message);

        $this->assertSame("the message", $this->readLogs($filepath)[0]['message']);
    }


    public function testUnencodableContextThrowsAndWritesNothing() : void
    {
        $filepath = "{$this->m_tempDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        $logger->info("before");
        $thrown = null;

        try
        {
            $logger->info("Payment failed", ['amount' => INF]);
        }
        catch (ExceptionFailedToEncodeLog $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionFailedToEncodeLog to be thrown.");
        $this->assertSame("Failed to JSON encode log message 'Payment failed': Inf and NaN cannot be JSON encoded", $thrown->getMessage());
        $this->assertInstanceOf(\JsonException::class, $thrown->getPrevious());
        $this->assertCount(1, $this->readLogs($filepath), "The file should be unchanged and still valid JSON.");
    }


    public function testConstructorExceptionMessageContainsPathAndReason() : void
    {
        $filepath = "{$this->m_tempDir}/missing-dir/logs.json";

        $this->expectException(ExceptionFailedToOpenFile::class);
        $this->expectExceptionMessageMatches("#Failed to open JSON log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#");

        new JsonFileLogger($filepath);
    }


    public function testLogExceptionMessageContainsPathAndReasonWhenDirectoryRemoved() : void
    {
        $subDir = "{$this->m_tempDir}/sub-dir";
        mkdir($subDir);
        $filepath = "{$subDir}/logs.json";
        $logger = new JsonFileLogger($filepath);
        unlink($filepath);
        rmdir($subDir);

        $this->expectException(ExceptionFailedToOpenFile::class);
        $this->expectExceptionMessageMatches("#Failed to open JSON log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#");

        $logger->info("hello");
    }


    public function testFailureToOpenFileDoesNotTriggerPhpWarning() : void
    {
        $filepath = "{$this->m_tempDir}/missing-dir/logs.json";
        $errors = [];

        // Mimic a framework that converts warnings into exceptions, by recording any that reach the app's handler.
        set_error_handler(function (int $errno, string $errstr) use (&$errors) : bool {
            $errors[] = $errstr;
            return true;
        });

        try
        {
            new JsonFileLogger($filepath);
        }
        catch (ExceptionFailedToOpenFile)
        {
            // Expected.
        }
        finally
        {
            restore_error_handler();
        }

        $this->assertSame([], $errors, "fopen()'s warning should not reach the application's error handler.");
    }


    public function testExceptionContainsReasonEvenWhenAppHasAnErrorHandler() : void
    {
        $filepath = "{$this->m_tempDir}/missing-dir/logs.json";
        set_error_handler(fn(int $errno, string $errstr) : bool => true);
        $message = "";

        try
        {
            new JsonFileLogger($filepath);
        }
        catch (ExceptionFailedToOpenFile $exception)
        {
            $message = $exception->getMessage();
        }
        finally
        {
            restore_error_handler();
        }

        $this->assertStringContainsString("No such file or directory", $message);
    }


    public function testErrorHandlerIsRestoredAfterOpeningFile() : void
    {
        $previousHandler = fn(int $errno, string $errstr) : bool => true;
        set_error_handler($previousHandler);

        try
        {
            new JsonFileLogger("{$this->m_tempDir}/logs.json");
            $currentHandler = set_error_handler(null);
            restore_error_handler();
        }
        finally
        {
            restore_error_handler();
        }

        $this->assertSame($previousHandler, $currentHandler);
    }


    /**
     * Read and decode the logs from a JSON log file, failing the test if the file is not valid JSON.
     * @param string $filepath - the path to the JSON log file.
     * @return array - the decoded logs.
     */
    private function readLogs(string $filepath) : array
    {
        return json_decode(file_get_contents($filepath), true, 512, JSON_THROW_ON_ERROR);
    }
}
