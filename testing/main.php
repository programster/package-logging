<?php

require_once(__DIR__ . '/vendor/autoload.php');

define('DB_HOST', 'localhost');
define('DB_NAME', 'test');
define('DB_PORT', '5432');
define('DB_USER', 'postgres');
define('DB_PASSWORD', 'my_password');

function getConnection($host, $db_name, $user, $password, $port="5432", $use_utf8=true, $force_new=false, $useAsync=false)
{
    if ($force_new && $useAsync)
    {
        $force_new = false;
    }

    $connString =
        "host=" . $host
        . " dbname=" . $db_name
        . " user=" . $user
        . " password=" . $password
        . " port=" . $port;

    if ($use_utf8)
    {
        $connString .= " options='--client_encoding=UTF8'";
    }

    print $connString . PHP_EOL;

    if ($useAsync)
    {
        $connection = pg_connect($connString, PGSQL_CONNECT_ASYNC);
    }
    elseif ($force_new)
    {
        $connection = pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
    }
    else
    {
        $connection = pg_connect($connString);
    }

    if ($connection == false)
    {
        throw new Exception("Failed to initialize database connection.");
    }

    return $connection;
}


function main()
{
    $connection = getConnection(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);
    $logger = new Programster\Log\PgSqlLogger($connection, "logs");
    $logger->debug("This is an info log", ['name' => 'value']);
    $logger->notice("This is a notice log", ['name' => 'value']);
    $logger->info("This is an info log", ['name' => 'value']);
    $logger->warning("This is a warning log", ['name' => 'value']);
    $logger->alert("This is an alert log", ['name' => 'value']);
    $logger->emergency("This is an emergency log", ['name' => 'value']);
    $logger->critical("This is a critical log", ['name' => 'value']);
    $logger->log(Psr\Log\LogLevel::WARNING, "This is another warning log", ['name' => 'value']);
    $logger->info("You can log with just a message and no context.");
}
