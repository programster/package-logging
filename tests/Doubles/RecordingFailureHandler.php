<?php

/*
 * A test failure handler that records every call to it, so that tests can assert on what it was given.
 * Deliberately not readonly, as it has to record calls as they come in.
 */

declare(strict_types = 1);
namespace Programster\Log\Tests\Doubles;


use Programster\Log\Interfaces\InterfaceFailureHandler;

final class RecordingFailureHandler implements InterfaceFailureHandler
{
    /** @var array<int, array{failures: \Throwable[], messageWasLogged: bool}> */
    private array $m_calls = [];


    public function handleFailures(array $failures, bool $messageWasLogged) : void
    {
        $this->m_calls[] = [
            'failures' => $failures,
            'messageWasLogged' => $messageWasLogged,
        ];
    }


    /**
     * @return array<int, array{failures: \Throwable[], messageWasLogged: bool}>
     */
    public function getCalls() : array
    {
        return $this->m_calls;
    }
}
