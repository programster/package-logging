<?php

/*
 * An exception thrown when the FailoverLogger is given a negative retry delay.
 */

declare(strict_types = 1);
namespace Programster\Log\Exceptions;


final class ExceptionInvalidRetryDelay extends \Exception
{

}
