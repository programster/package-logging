<?php

/*
 * This class is a logger which logs to a PostgreSQL database connection.
 * Requires a database table with at LEAST the following fields
 *  - message - (varchar/text)
 *  - priority - (int)
 *  - context - (full text for json)
 */

declare(strict_types = 1);
namespace Programster\Log;


final class PgSqlLogger extends AbstractLogger
{
    protected $m_connection;
    protected $m_logTable;


    /**
     * Creates a database logger using the provided pgsql connection and table name
     * @param $connection - the connection resource from pg_connect
     * @param string $logTable - the name of the table that will store the logs
     * @return void - (constructor)
     */
    public function __construct($connection, string $logTable)
    {
        $this->m_connection = $connection;
        $this->m_logTable = $logTable;
    }


    private function checkIfLogsTableExists()
    {
        $query = "SELECT EXISTS(
            SELECT *
            FROM information_schema.tables
            WHERE
              table_schema = 'company3' AND
              table_name = ''
        )";
    }


    private function createLogsTable()
    {
        $createQuery =
            "CREATE TABLE " . $this->m_logTable . " (
                uuid UUID NOT NULL,
                message TEXT NOT NULL,
                context JSON NOT NULL,
                created_at INT NOT NULL,
                PRIMARY KEY (uuid)
            )";

        $createResult = pg_query($this->m_connection, $createQuery);

        if ($createResult === FALSE)
        {
            throw new \Exception("Failed to create the logs table.");
        }

        $indexQuery = "CREATE INDEX ON " . $this->m_logTable . ' ("created_at")';
        $indexResult = pg_query($this->m_connection, $indexQuery);

        if ($indexResult === FALSE)
        {
            throw new \Exception("Failed to create index on logging table.");
        }
    }


    /**
     * Logs with an arbitrary level.
     *
     * @param int $level - the priority of the message - see LogLevel class
     * @param string $message -  the message of the error, e.g "failed to connect to db"
     * @param array $context - name value pairs providing context to error, e.g. "dbname => "yolo")
     *
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        $this->validateLogLevel($level);
        $contextString = json_encode($context, JSON_UNESCAPED_SLASHES);

        $params = array(
            'uuid' => pg_escape_string($this->m_connection, $this->generateUuid()),
            'message'  => pg_escape_string($this->m_connection, $message),
            'level' => $level,
            'context'  => pg_escape_string($this->m_connection, $contextString),
        );

        $query =
            "INSERT INTO `" . $this->m_logTable . "` (uuid, message, level, context)" .
            " VALUES ('{$params['uuid']}','{$params['message']}', '{$params['level']}', '{$params['context']}')";

        $result = pg_query($this->m_connection, $query);

        if ($result === FALSE)
        {
            $err_msg =
                'Failed to insert log into database: ' . PHP_EOL .
                'Query: ' . $query . PHP_EOL;

            throw new \Exception($err_msg);
        }
    }


    /**
     * Generates a sequential UUID4.
     * @staticvar type $factory
     * @return string - the generated UUID
     */
    private function generateUuid() : string
    {
        static $factory = null;

        if ($factory === null)
        {
            $factory = new \Ramsey\Uuid\UuidFactory();

            $generator = new \Ramsey\Uuid\Generator\CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            );

            $codec = new Ramsey\Uuid\Codec\TimestampFirstCombCodec($factory->getUuidBuilder());

            $factory->setRandomGenerator($generator);
            $factory->setCodec($codec);
        }

        Ramsey\Uuid\Uuid::setFactory($factory);
        $uuidString1 = Ramsey\Uuid\Uuid::uuid4()->toString();
        return $uuidString1;
    }
}