# PHP Logging Package
A PHP package for logging that is compliant with [PSR-3](https://www.php-fig.org/psr/psr-3/).

## Install

```bash
composer require programster/log
```

## Example Usage

```php
/**
 * Get a logger for logging all the things.
 * @return \Psr\Log\LoggerInterface
 */
public function getLogger() : \Psr\Log\LoggerInterface
{
    return new \Programster\Log\FileLogger(filepath: "/path/to/my/logs.csv");
}
```

## Included Loggers
* EmailLogger - sends logs to email address(es)
* FileLogger - logs to a CSV file
* MinLevelLogger - logs to a provided logger, but only when the level is higher than a minimum (e.g. ignore debug logs).
* MultiLogger.php - allows one to provide multiple loggers in order to log to multiple places. E.g. send emails and record to a log file.
* MysqliLogger.php - log to a MySQL database.
* PgSqlLogger.php - Log to a PostgreSQL database
* PrefixLogger.php - logs to a provided logger, after putting a prefix on the message. Good for automatically prefixing a service name.
