<?php
namespace PHPDaemon\Core;

use PHPDaemon\Structures\StackCallbacks;

class EventLoop
{
    public static $instance;
    protected $base;
    protected $dnsBase;
    protected $callbacks;
    protected $stopped = true;

    public function __construct()
    {
        $this->base = new \EventBase;
        $this->callbacks = new StackCallbacks;
        $this->dnsBase = new \EventDnsBase($this->base, false); // @TODO: test with true
    }

    public static function init()
    {
        if (self::$instance !== null) {
            self::$instance->reinit();
        } else {
            self::$instance = new static;
        }
    }

    public function getBase()
    {
        return $this->base;
    }

    public function getDnsBase()
    {
        return $this->dnsBase;
    }

    public function reinit()
    {
        $this->base->reinit();
    }

    public function signal(...$args)
    {
        return \Event::signal($this->base, ...$args);
    }

    public function timer(...$args)
    {
        return \Event::timer($this->base, ...$args);
    }

    public function listener(...$args)
    {
        return new \EventListener($this->base, ...$args);
    }

    public function bufferEvent(...$args)
    {
        return new \EventBufferEvent($this->base, ...$args);
    }

    public function bufferEventSsl(...$args)
    {
        return \EventBufferEvent::sslSocket($this->base, ...$args);
    }

    public function stop()
    {
        $this->stopped = true;
        $this->interrupt();
    }

    public function interrupt($cb = null)
    {
        if ($cb !== null) {
            $this->callbacks->push($cb);
        }
        $this->base->exit();
    }

    public function event(...$args)
    {
        return new \Event($this->base, ...$args);
    }

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