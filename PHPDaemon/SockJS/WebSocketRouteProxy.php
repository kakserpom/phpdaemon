<?php
namespace PHPDaemon\SockJS;

use PHPDaemon\Core\Timer;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class WebSocketRouteProxy implements \PHPDaemon\WebSocket\RouteInterface
{
    use \PHPDaemon\Traits\StaticObjectWatchdog;

    protected $heartbeatTimer;

    protected $realRoute;

    protected $sockjs;

    /**
     * __construct
     * @param Application $sockjs
     * @param object $conn
     */
    public function __construct($sockjs, $route)
    {
        $this->sockjs = $sockjs;
        $this->realRoute = $route;
    }

    /**
     * __get
     * @param  string $k
     * @return mixed
     */
    public function &__get($k)
    {
        return $this->realRoute->{$k};
    }

    /**
     * __call
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $func = [$this->realRoute, $method];
        $func(...$args);
    }

    /**
     * Called when new frame received.
     * @param string $data Frame's contents.
     * @param integer $type Frame's type.
     * @return void
     */
    public function onFrame($data, $type)
    {
        foreach (explode("\n", $data) as $pct) {
            if ($pct === '') {
                continue;
            }
            $pct = json_decode($pct, true);
            if (isset($pct[0])) {
                foreach ($pct as $i) {
                    $this->onPacket(rtrim($i, "\n"), $type);
                }
            } else {
                $this->onPacket($pct, $type);
            }
        }
    }

    /**
     * onPacket
     * @param string $data Frame's contents.
     * @param integer $type Frame's type.
     * @return void
     */
    public function onPacket($frame, $type)
    {
        $this->realRoute->onFrame($frame, $type);
    }

    /**
     * realRoute onBeforeHandshake
     * @param  callable $cb
     * @return void|false
     */
    public function onBeforeHandshake($cb)
    {
        if (!method_exists($this->realRoute, 'onBeforeHandshake')) {
            return false;
        }
        $this->realRoute->onBeforeHandshake($cb);
    }

    /**
     * @TODO DESCR
     * @return void
     */
    public function onHandshake()
    {
        $this->realRoute->client->sendFrameReal('o');
        if (($f = $this->sockjs->config->heartbeatinterval->value) > 0) {
            $this->heartbeatTimer = setTimeout(function ($timer) {
                $this->realRoute->client->sendFrameReal('h');
                $timer->timeout();
            }, $f * 1e6);
            $this->realRoute->onHandshake();
        }
    }

    /**
     * @TODO DESCR
     * @return void
     */
    public function onWrite()
    {
        if (method_exists($this->realRoute, 'onWrite')) {
            $this->realRoute->onWrite();
        }
    }

    /**
     * @TODO DESCR
     * @return void
     */
    public function onFinish()
    {
        Timer::remove($this->heartbeatTimer);
        if ($this->realRoute) {
            $this->realRoute->onFinish();
            $this->realRoute = null;
        }
    }
}
