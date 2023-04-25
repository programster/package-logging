Logger PHP Package
==================

This is a [PSR-3](https://www.php-fig.org/psr/psr-3/) compatible logger package that provides some
abstract loggers that allow:

* Minimum level logging (MinLevelLogger)
* Logging to multiple loggers (MultiLogger)
* Prefix logging - add a prefix to all messages before logging to the underlying logger.
* File logger - log to a file.

This package provides a PHP enum for the log level to simplify things. Hopefully the PSR-3 package
will provide this itself at some point in the future.

You will likely want to take advantage of one of the following additional logging packages
that make use of this package.

* [PostgreSQL Logger](https://github.com/programster/package-pgsql-logger)- log to a PostgreSQL database. 
* [MySQL Logger](https://github.com/programster/package-mysql-logger) - log to a MySQL database.
* [Email Logger](https://github.com/programster/package-email-logger) - send logs to any number of email addresses.


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
