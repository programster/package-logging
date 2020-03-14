<?php


/*
 * Sends logs via email.
 */

declare(strict_types = 1);
namespace Programster\Log;


final class EmailLogger extends AbstractLogger
{
    private $m_emailer;
    private $m_subscribers;
    private $m_serviceName;


    /**
     * Creates a logger that logs to email addresses
     * @param \iRAP\Emailers\EmailerInterface $emailer - an emailer to send through
     * @param string $serviceName - the name of the service that is triggering the logs
     * @param \Programster\Log\EmailSubscriber $subscribers - any number of people to recieve the logs.
     */
    public function __construct(
        \iRAP\Emailers\EmailerInterface $emailer,
        string $serviceName,
        EmailSubscriber ...$subscribers
    )
    {
        $this->m_emailer = $emailer;
        $this->m_subscribers = $subscribers;
        $this->m_serviceName = $serviceName;
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
        $params = array(
            'message'  => $message,
            'priority' => $level,
            'context'  => json_encode($context)
        );

        $subject = $this->m_serviceName . ' - ' . LogLevel::get_name($level);

        $body =
            '<p>This is an automated log from [ ' . $this->m_serviceName . ' ]</p>' .
            '<h3>Error Message:</h3>' .
            '<pre>' . $message . '</pre>' .
            '<h3>Context:</h3>' .
            '<pre>' . print_r($params, true) . '</pre>';

        foreach ($this->m_subscribers as $subscriber)
        {
            $this->m_emailer->send(
                $subscriber->getName(),
                $subscriber->getEmail(),
                $subject,
                $body,
                true // htmlFormat
            );
        }
    }
}
