<?php
/*
 * This class is a logger which logs to a mysqli database connection.
 * Requires a database table with at LEAST the following fields
 *  - message - (varchar/text)
 *  - priority - (int)
 *  - context - (full text for json)
 */

namespace Programster\Log;


class MysqliLogger extends \Psr\Log\AbstractLogger
{
    protected $m_connection;
    protected $m_logTable;
    protected $m_minLogLevel = 0;


    /**
     * Creates a database logger using the provided mysqli connection and table
     * @param mysqli $connection - a connected mysqli instance that we can log to
     * @param type $table - the name of the table that will store the logs
     * @return void - (constructor)
     */
    public function __construct(\mysqli $connection, $logTable)
    {
        $this->m_connection = $connection;
        $this->m_logTable = $logTable;
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
        if ($level >= $this->m_minLogLevel)
        {
            $mysqli_conn = $this->getConnection();
            $json_context_string = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $params = array(
                'message'  => $message,
                'priority' => $level,
                'context'  => $json_context_string
            );

            $query = "INSERT INTO `" . $this->m_logTable . "` SET " .
                    \iRAP\CoreLibs\MysqliLib::generateQueryPairs($params, $mysqli_conn);

            $result = $mysqli_conn->query($query);

            if ($result === FALSE)
            {
                $err_msg =
                    'Failed to insert log into database: ' . $mysqli_conn->error . PHP_EOL .
                    'Query: ' . $query . PHP_EOL;

                # Output to the terminal aswell
                print PHP_EOL . $err_msg . PHP_EOL;

                throw new \Exception($err_msg);
            }
        }
    }


    /**
     * Closes the database connection if it is open.
     * @param connectionName - the name of the connection we wish to close off.
     * @return void
     */
    public function closeConnection()
    {
        if ($this->m_connection !== null)
        {
            $this->m_connection->close();
        }
    }


    /**
     * Checks whether we have been disconnected from the database. This uses the mysqli_ping method
     * that will automatically reconnect if you are NOT using the mysqlnd package.
     * @return boolean - true if connected, false if not.
     */
    public function isConnected()
    {
        return mysqli_ping($this->m_connection);
    }


    /**
     * Gets the mysqli connection that the databaselogger is using
     * @return \mysqli
     */
    public function getConnection() { return $this->m_connection; }
}
