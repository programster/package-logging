<?php

/*
 * An exception thrown when a RetryDelay is given a number of seconds that isn't finite and more than 0.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidRetryDelay extends \Exception
{

}
