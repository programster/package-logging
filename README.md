# PHP Logging Package
A PHP package for logging that is compliant with [PSR-3](https://www.php-fig.org/psr/psr-3/).

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
