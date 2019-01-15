<?php
namespace PHPDaemon\Traits;

use PHPDaemon\Core\CallbackWrapper;

/**
 * Event handlers trait
 * @package PHPDaemon\Traits
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
trait EventHandlers
{
    /**
     * @var array Event handlers
     */
    protected $eventHandlers = [];

    /**
     * @var boolean Unshift $this to arguments of callback?
     */
    protected $addThisToEvents = true;

    /**
     * @var string Last called event name
     */
    protected $lastEventName;

    /**
     * Propagate event
     * @param  string $name Event name
     * @param  mixed ...$args Arguments
     * @return this
     */
    public function event($name, ...$args)
    {
        if ($this->addThisToEvents) {
            array_unshift($args, $this);
        }
        if (isset($this->eventHandlers[$name])) {
            $this->lastEventName = $name;
            foreach ($this->eventHandlers[$name] as $cb) {
                if ($cb(...$args) === true) {
                    return $this;
                }
            }
        }
        return $this;
    }

    /**
     * Propagate event
     * @param  string $name Event name
     * @param  mixed ...$args Arguments
     * @return this
     */
    public function trigger($name, ...$args)
    {
        if ($this->addThisToEvents) {
            array_unshift($args, $this);
        }
        if (isset($this->eventHandlers[$name])) {
            $this->lastEventName = $name;
            foreach ($this->eventHandlers[$name] as $cb) {
                if ($cb(...$args) === true) {
                    return $this;
                }
            }
        }
        return $this;
    }

    /**
     * Propagate event
     * @param  string $name Event name
     * @param  mixed ...$args Arguments
     * @return integer
     */
    public function triggerAndCount($name, ...$args)
    {
        if ($this->addThisToEvents) {
            array_unshift($args, $this);
        }
        $cnt = 0;
        if (isset($this->eventHandlers[$name])) {
            $this->lastEventName = $name;
            foreach ($this->eventHandlers[$name] as $cb) {
                if ($cb(...$args) !== 0) {
                    ++$cnt;
                }
            }
        }
        return $cnt;
    }

    /**
     * Use it to define event name, when one callback was bind to more than one events
     * @return string
     */
    public function getLastEventName()
    {
        return $this->lastEventName;
    }

    /**
     * Bind event or events
     * @alias EventHandlers::bind
     * @param string|array $event Event name
     * @param callable $cb Callback
     * @return this
     */
    public function on($event, $cb)
    {
        return $this->bind($event, $cb);
    }

    /**
     * Bind event or events
     * @param string|array $event Event name
     * @param callable $cb Callback
     * @return this
     */
    public function bind($event, $cb)
    {
        if ($cb !== null) {
            $cb = CallbackWrapper::wrap($cb);
        }
        $event = (array) $event;
        foreach ($event as $e) {
            CallbackWrapper::addToArray($this->eventHandlers[$e], $cb);
        }
        return $this;
    }

    /**
     * Unbind event(s) or callback from event(s)
     * @alias EventHandlers::unbind
     * @param string|array $event Event name
     * @param callable $cb Callback, optional
     * @return this
     */
    public function off($event, $cb = null)
    {
        return $this->unbind($event, $cb);
    }

    /**
     * Unbind event(s) or callback from event(s)
     * @param string|array $event Event name
     * @param callable $cb Callback, optional
     * @return this
     */
    public function unbind($event, $cb = null)
    {
        if ($cb !== null) {
            $cb = CallbackWrapper::wrap($cb);
        }
        $event = (array) $event;
        $success = true;
        foreach ($event as $e) {
            if (!isset($this->eventHandlers[$e])) {
                $success = false;
                continue;
            }
            if ($cb === null) {
                unset($this->eventHandlers[$e]);
                continue;
            }
            CallbackWrapper::removeFromArray($this->eventHandlers[$e], $cb);
        }
        return $this;
    }

    /**
     * Clean up all events
     * @return void
     */
    protected function cleanupEventHandlers()
    {
        $this->eventHandlers = [];
        //Daemon::log('clean up event handlers '.get_class($this). ' -- '.$this->attrs->server['REQUEST_URI']. PHP_EOL .Debug::backtrace());
    }
}
