<?php
namespace PHPDaemon\Core;
use PHPDaemon\Structures\StackCallbacks;

class EventLoop
{
    protected $base;
    protected $dnsBase;
    protected $callbacks;
    protected $stopped = true;

    public function __construct() {
        $this->base = new \EventBase;
        $this->callbacks = new StackCallbacks;
        $this->dnsBase = new \EventDnsBase($this->base, false); // @TODO: test with true
    }

    public function getBase() {
        return $this->base;
    }

    public function getDnsBase() {
        return $this->dnsBase;
    }

    public function reinit() {
        $this->base->reinit();
        return $this;
    }

    public function signal(...$args) {
        return \Event::signal($this->base, ...$args);
    }

    public function interrupt($cb = null) {
        if ($cb !== null) {
            $this->callbacks->push($cb);
        }
        $this->base->exit();
    }

    public function stop() {
        $this->stopped = true;
        $this->interrupt();
    }

    public function run() {
        $this->stopped = false;
        while (!$this->stopped) {
            $this->callbacks->executeAll($this);
            if (!$this->base->dispatch()) {
                break;
            }
        }
    }
}