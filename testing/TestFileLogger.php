<?php

require_once(__DIR__ . '/../vendor/autoload.php');


final class TestFileLogger
{
    public function __construct()
    {

    }

    public function run()
    {
        $logFilepath = tempnam(sys_get_temp_dir(), "logs-");
        $logger = new \Programster\Log\FileLogger($logFilepath);
        $logger->debug("This is an info log", ['name' => 'value']);
        sleep(1);
        $logger->notice("This is a notice log", ['name' => 'value']);
        sleep(1);
        $logger->info("This is an info log", ['name' => 'value']);
        sleep(1);
        $logger->warning("This is a warning log", ['name' => 'value']);
        sleep(1);
        $logger->alert("This is an alert log", ['name' => 'value']);
        sleep(1);
        $logger->emergency("This is an emergency log", ['name' => 'value']);
        sleep(1);
        $logger->critical("This is a critical log", ['name' => 'value']);
        $logger->log(Psr\Log\LogLevel::WARNING, "This is another warning log", ['name' => 'value']);
        $logger->info("You can log with just a message and no context.");
        print "Check the log file at: {$logFilepath}" . PHP_EOL;
    }
}

$test = new TestFileLogger();
$test->run();
