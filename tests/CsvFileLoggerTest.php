<?php

declare(strict_types = 1);
namespace Programster\Log\Tests;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Programster\Log\CsvFileLogger;
use Programster\Log\Exceptions\ExceptionFailedToEncodeLog;
use Programster\Log\Exceptions\ExceptionFailedToOpenFile;
use Programster\Log\Exceptions\ExceptionInvalidLogLevel;

final class CsvFileLoggerTest extends TestCase
{
    private string $m_tempDir;


    protected function setUp() : void
    {
        $this->m_tempDir = sys_get_temp_dir() . "/csv-file-logger-test-" . bin2hex(random_bytes(8));
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


    public function testLogsAreWrittenAsCsvRows() : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->info("first", ['key' => 'value']);
        $logger->error("second");

        $rows = $this->readRows($filepath);
        $this->assertCount(2, $rows);
        $this->assertSame(['info', 'first', '{"key":"value"}'], array_slice($rows[0], 1));
        $this->assertSame(['error', 'second', '[]'], array_slice($rows[1], 1));
    }


    #[DataProvider('provideValidLevels')]
    public function testAcceptsEveryLevelFormatAndWritesTheLevelString(mixed $level, string $expectedLevel) : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->log($level, "hello");

        $this->assertSame($expectedLevel, $this->readRows($filepath)[0][1]);
    }


    #[DataProvider('provideInvalidLevels')]
    public function testRejectsInvalidLevelsWithPsr3ExceptionAndWritesNothing(mixed $level) : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
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
        $this->assertSame([], $this->readRows($filepath));
    }


    public static function provideValidLevels() : array
    {
        return LevelProviders::validLevels();
    }


    public static function provideInvalidLevels() : array
    {
        return LevelProviders::invalidLevels();
    }


    public function testWritesToNewFileAfterRotation() : void
    {
        // E.g. logrotate renaming the file away whilst the application is running.
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->info("before");
        rename($filepath, "{$filepath}.1");

        $logger->info("after");

        $this->assertSame(["after"], array_column($this->readRows($filepath), 2));
        $this->assertSame(["before"], array_column($this->readRows("{$filepath}.1"), 2), "The rotated file should not be written to.");
    }


    public function testRecreatesFileIfDeletedWhilstRunning() : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->info("before");
        unlink($filepath);

        $logger->info("after");

        $this->assertSame(["after"], array_column($this->readRows($filepath), 2));
    }


    public function testConcurrentProcessesDoNotInterleaveRows() : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $autoloadPath = __DIR__ . "/../vendor/autoload.php";
        $numProcesses = 4;
        $logsPerProcess = 50;

        // A general check that concurrent writers don't corrupt the file. Note that on a local Linux filesystem
        // this passes even without the lock, as the kernel keeps each append whole. The lock matters on
        // filesystems that don't guarantee that, such as NFS, which a test can't easily reproduce.
        $script = 'require $argv[1]; $l = new Programster\Log\CsvFileLogger($argv[2]); ' .
            'for ($i = 0; $i < (int)$argv[4]; $i++) { $l->info("p{$argv[3]}-{$i}", ["padding" => str_repeat("x", 100000)]); }';

        $processes = [];

        for ($p = 0; $p < $numProcesses; $p++)
        {
            $command = [PHP_BINARY, '-r', $script, $autoloadPath, $filepath, (string)$p, (string)$logsPerProcess];
            $processes[] = proc_open($command, [], $pipes);
        }

        foreach ($processes as $process)
        {
            $this->assertSame(0, proc_close($process), "A logging process failed.");
        }

        $rows = $this->readRows($filepath);
        $this->assertCount($numProcesses * $logsPerProcess, $rows);

        foreach ($rows as $row)
        {
            $this->assertCount(4, $row, "Every row should have exactly 4 fields.");
            $this->assertSame(str_repeat("x", 100000), json_decode($row[3], true, 512, JSON_THROW_ON_ERROR)['padding']);
        }
    }


    public function testInvalidUtf8InContextIsReplacedRatherThanDropped() : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $logger->info("hello", ['key' => "bad \xB1 context"]);

        $context = json_decode($this->readRows($filepath)[0][3], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("bad \u{FFFD} context", $context['key']);
    }


    public function testUnencodableContextThrowsAndWritesNothing() : void
    {
        $filepath = "{$this->m_tempDir}/logs.csv";
        $logger = new CsvFileLogger($filepath);
        $thrown = null;

        try
        {
            $logger->info("Payment failed", ['amount' => NAN]);
        }
        catch (ExceptionFailedToEncodeLog $exception)
        {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, "Expected ExceptionFailedToEncodeLog to be thrown.");
        $this->assertSame("Failed to JSON encode log message 'Payment failed': Inf and NaN cannot be JSON encoded", $thrown->getMessage());
        $this->assertInstanceOf(\JsonException::class, $thrown->getPrevious());
        $this->assertSame([], $this->readRows($filepath));
    }


    public function testConstructorExceptionMessageContainsPathAndReason() : void
    {
        $filepath = "{$this->m_tempDir}/missing-dir/logs.csv";

        $this->expectException(ExceptionFailedToOpenFile::class);
        $this->expectExceptionMessageMatches("#Failed to open CSV log file '" . preg_quote($filepath, '#') . "'.*No such file or directory#");

        new CsvFileLogger($filepath);
    }


    /**
     * Read the rows from a CSV log file.
     * @param string $filepath - the path to the CSV log file.
     * @return array - one array of fields per row.
     */
    private function readRows(string $filepath) : array
    {
        $rows = [];
        $handle = fopen($filepath, "r");

        while (($row = fgetcsv($handle, escape: "\\")) !== false)
        {
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }
}
