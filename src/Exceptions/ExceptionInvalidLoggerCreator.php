<?php

/*
 * An exception thrown when a CachingLoggerFactory is given a closure that can't be relied on to create a logger: one
 * that has required parameters, or doesn't declare that it returns a LoggerInterface.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidLoggerCreator extends \Exception
{

}
