<?php

/*
 * A test failure handler that records every call to it, so that tests can check what it was given.
 * Deliberately not readonly, as it has to record calls as they come in.
 */

declare(strict_types = 1);
namespace Programster\Log\Testing\Doubles;


use Programster\Log\Collections\LoggerFailureCollection;
use Programster\Log\Interfaces\InterfaceFailureHandler;

final class RecordingFailureHandler implements InterfaceFailureHandler
{
    /** @var list<array{failures: LoggerFailureCollection, messageWasLogged: bool}> */
    private array $m_calls = [];


    public function handleFailures(LoggerFailureCollection $failures, bool $messageWasLogged) : void
    {
        $this->m_calls[] = [
            'failures' => $failures,
            'messageWasLogged' => $messageWasLogged,
        ];
    }


    # Accessors
    /** @return list<array{failures: LoggerFailureCollection, messageWasLogged: bool}> */
    public function getCalls() : array { return $this->m_calls; }
}
