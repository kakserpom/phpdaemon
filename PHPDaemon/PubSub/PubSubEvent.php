<?php
namespace PHPDaemon\PubSub;

/**
 * PubSubEvent
 * @package PHPDaemon\PubSub
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class PubSubEvent extends \SplObjectStorage
{
    use \PHPDaemon\Traits\ClassWatchdog;
    use \PHPDaemon\Traits\StaticObjectWatchdog;

    /**
     * @var array Subscriptions
     */
    public $sub = [];

    /**
     * @var callable Activation callback
     */
    public $actCb;

    /**
     * @var callable Deactivation callback
     */
    public $deactCb;

    protected $storage;

    /**
     * Constructor
     */
    public function __construct($act = null, $deact = null)
    {
        if ($act !== null) {
            $this->actCb = $act;
        }
        if ($deact !== null) {
            $this->deactCb = $deact;
        }
        $this->storage = new \SplObjectStorage;
    }

    /**
     * Sets onActivation callback
     * @param  callable $cb Callback
     * @return this
     */
    public function onActivation($cb)
    {
        $this->actCb = $cb;
        return $this;
    }

    /**
     * Sets onDeactivation callback
     * @param callable $cb Callback
     * @return this
     */
    public function onDeactivation($cb)
    {
        $this->deactCb = $cb;
        return $this;
    }

    /**
     * Init
     * @return object
     */
    public static function init()
    {
        return new static;
    }

    /**
     * Subscribe
     * @param  object   $obj Subcriber object
     * @param  callable $cb  Callback
     * @return this
     */
    public function sub($obj, $cb)
    {
        $act = $this->count() === 0;
        $this->attach($obj, $cb);
        if ($act) {
            if (($func = $this->actCb) !== null) {
                $func($this);
            }
        }
        return $this;
    }

    /**
     * Unsubscripe
     * @param  object $obj Subscriber object
     * @return this
     */
    public function unsub($obj)
    {
        $this->detach($obj);
        if ($this->count() === 0) {
            if (($func = $this->deactCb) !== null) {
                $func($this);
            }
        }
        return $this;
    }

    /**
     * Publish
     * @param  mixed $data Data
     * @return this
     */
    public function pub($data)
    {
        foreach ($this as $obj) {
            $func = $this->getInfo();
            $func($data);
        }
        return $this;
    }
}
