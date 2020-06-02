<?php
namespace PHPDaemon\Core;

use PHPDaemon\Structures\StackCallbacks;

class EventLoop
{
    /**
     * @var self
     */
    public static $instance;

    /**
     * @var \EventBase
     */
    protected $base;

    /**
     * @var \EventDnsBase
     */
    protected $dnsBase;

    /**
     * @var StackCallbacks
     */
    protected $callbacks;

    /**
     * @var bool
     */
    protected $stopped = true;

    /**
     * EventLoop constructor.
     */
    public function __construct()
    {
        $this->base = new \EventBase;
        $this->callbacks = new StackCallbacks;
        $this->dnsBase = new \EventDnsBase($this->base, false); // @TODO: test with true
    }

    /**
     * Init
     */
    public static function init()
    {
        if (self::$instance !== null) {
            self::$instance->reinit();
        } else {
            self::$instance = new static;
        }
    }

    /**
     * Free
     */
    public function free() {
        $this->base->free();
    }

    /**
     * @return \EventBase
     */
    public function getBase()
    {
        return $this->base;
    }

    /**
     * @return \EventDnsBase
     */
    public function getDnsBase()
    {
        return $this->dnsBase;
    }

    /**
     * Reinit
     */
    public function reinit()
    {
        $this->base->reinit();
    }

    /**
     * @param array ...$args
     * @return mixed
     */
    public function signal(...$args)
    {
        return \Event::signal($this->base, ...$args);
    }

    /**
     * @param array ...$args
     * @return mixed
     */
    public function timer(...$args)
    {
        return \Event::timer($this->base, ...$args);
    }

    /**
     * @param array ...$args
     * @return \EventListener
     */
    public function listener(...$args)
    {
        return new \EventListener($this->base, ...$args);
    }

    /**
     * @param array ...$args
     * @return \EventBufferEvent
     */
    public function bufferEvent(...$args)
    {
        return new \EventBufferEvent($this->base, ...$args);
    }

    /**
     * @param array ...$args
     * @return mixed
     */
    public function bufferEventSsl(...$args)
    {
        return \EventBufferEvent::sslSocket($this->base, ...$args);
    }

    /**
     * Stop
     */
    public function stop()
    {
        $this->stopped = true;
        $this->interrupt();
    }

    /**
     * @param null $cb
     */
    public function interrupt($cb = null)
    {
        if ($cb !== null) {
            $this->callbacks->push($cb);
        }
        $this->base->exit();
    }

    /**
     * @param array ...$args
     * @return \Event
     */
    public function event(...$args)
    {
        return new \Event($this->base, ...$args);
    }

    /**
     * Run
     */
    public function run()
    {
        $this->stopped = false;
        while (!$this->stopped) {
            $this->callbacks->executeAll($this);
            if (!$this->base->dispatch()) {
                break;
            }
        }
    }
}