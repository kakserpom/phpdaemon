<?php

namespace PHPDaemon\Exceptions;

class ClearStack extends \Exception
{
    /**
     * Thread object
     * @var object Thread
     */
    protected $thread;

    /**
     * @param string  $msg Message
     * @param integer $code Code
     * @param \PHPDaemon\Thread\Generic $thread
     */
    public function __construct($msg, $code, $thread = null)
    {
        parent::__construct($msg, $code);
        $this->thread = $thread;
    }

    /**
     * Gets associated Thread object
     * @return object Thread
     */
    public function getThread()
    {
        return $this->thread;
    }
}
