<?php

/*
 * A subscriber object for subscribing to email logs.
 */

declare(strict_types = 1);
namespace Programster\Log;


final class EmailSubscriber
{
    private $m_name;
    private $m_email;


    public function __construct(string $name, string $email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false)
        {
            throw new ExceptionInvalidEmail("Invalid email provided: {$email}");
        }

        $this->m_name = $name;
        $this->m_email = $email;
    }


    # accessors
    public function getName() : string { return $this->m_name; }
    public function getEmail() : string { return $this->m_email; }
}

