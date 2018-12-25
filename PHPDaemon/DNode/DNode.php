<?php
namespace PHPDaemon\DNode;

use PHPDaemon\Exceptions\ProtocolError;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * DNode
 * @package PHPDaemon\WebSocket\Traits
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
trait DNode
{
    /**
     * @var array Associative array of callback functions registered by callRemote()
     */
    protected $callbacks = [];

    /**
     * @var array Associative array of persistent callback functions registered by callRemote()
     */
    protected $persistentCallbacks = [];

    /**
     * @var boolean If true, callRemote() will register callbacks as persistent ones
     */
    protected $persistentMode = false;

    /**
     * @var integer Incremental counter of callback functions registered by callRemote()
     */
    protected $counter = 0;

    /**
     * @var array Associative array of registered remote methods (received in 'methods' call)
     */
    protected $remoteMethods = [];

    /**
     * @var array Associative array of local methods, set by defineLocalMethods()
     */
    protected $localMethods = [];

    /**
     * @var boolean Was this object cleaned up?
     */
    protected $cleaned = false;

    /**
     * @var boolean Should __call method call parent::__call()?
     */
    protected $magicCallParent = false;

    /**
     * Ensures that the variable passed by reference holds a valid callback-function
     * If it doesn't, its value will be reset to null
     * @param  mixed &$arg Argument
     * @return boolean
     */
    public static function ensureCallback(&$arg)
    {
        if ($arg instanceof \Closure) {
            return true;
        }
        if (is_array($arg) && sizeof($arg) === 2) {
            if (isset($arg[0]) && $arg[0] instanceof \PHPDaemon\WebSocket\Route) {
                if (isset($arg[1]) && is_string($arg[1]) && strncmp($arg[1], 'remote_', 7) === 0) {
                    return true;
                }
            }
        }
        $arg = null;
        return false;
    }

    /**
     * Encodes value into JSON for debugging purposes
     * @param mixed $m Data
     * @return void
     */
    public static function toJsonDebug($m)
    {
        static::toJsonDebugResursive($m);
        return static::toJson($m);
    }

    /**
     * Recursion handler for toJsonDebug()
     * @param  array &$a Data
     * @return void
     */
    public static function toJsonDebugResursive(&$m)
    {
        if ($m instanceof \Closure) {
            $m = '__CALLBACK__';
        } elseif (is_array($m)) {
            if (sizeof($m) === 2 && isset($m[0]) && $m[0] instanceof \PHPDaemon\WebSocket\Route) {
                if (isset($m[1]) && is_string($m[1]) && strncmp($m[1], 'remote_', 7) === 0) {
                    $m = '__CALLBACK__';
                }
            } else {
                foreach ($m as &$v) {
                    static::toJsonDebugResursive($v);
                }
            }
        } elseif (is_object($m)) {
            foreach ($m as &$v) {
                static::toJsonDebugResursive($v);
            }
        }
    }

    /**
     * Default onHandshake() method
     * @return void
     */
    public function onHandshake()
    {
        $this->defineLocalMethods();
    }

    /**
     * Defines local methods
     * @param  array $arr Associative array of callbacks (methodName => callback)
     * @return void
     */
    protected function defineLocalMethods($arr = [])
    {
        foreach (get_class_methods($this) as $m) {
            if (substr($m, -6) === 'Method') {
                $k = substr($m, 0, -6);
                if ($k === 'methods') {
                    continue;
                }
                $arr[$k] = [$this, $m];
            }
        }
        foreach ($arr as $k => $v) {
            $this->localMethods[$k] = $v;
        }
        $this->persistentMode = true;
        $this->callRemote('methods', $arr);
        $this->persistentMode = false;
    }

    /**
     * Calls a remote method
     * @param  string $method Method name
     * @param  mixed ...$args Arguments
     * @return this
     */
    public function callRemote($method, ...$args)
    {
        $this->callRemoteArray($method, $args);
        return $this;
    }


    /**
     * Export object methods
     * @param $object
     * @return array
     */
    public static function exportObjectMethods($object) {
        $methods = [];
        foreach (get_class_methods($object) as $method) {
            if ($method[0] === '_') {
                continue;
            }
            $methods[$method] = [$object, $method];
        }
        return $methods;
    }

    /**
     * Calls a remote method with array of arguments
     * @param  string $method Method name
     * @param  array $args Arguments
     * @return this
     */
    public function callRemoteArray($method, $args)
    {
        if (isset($this->remoteMethods[$method])) {
            $this->remoteMethods[$method](...$args);
            return $this;
        }
        $pct = [
            'method' => $method,
        ];
        if (sizeof($args)) {
            $callbacks = [];
            $path = [];
            $this->extractCallbacks($args, $callbacks, $path);
            $pct['arguments'] = $args;
            if (sizeof($callbacks)) {
                $pct['callbacks'] = $callbacks;
            }
        }
        $this->sendPacket($pct);
        return $this;
    }

    /**
     * Extracts callback functions from array of arguments
     * @param  array &$args Arguments
     * @param  array &$list Output array for 'callbacks' property
     * @param  array &$path Recursion path holder
     * @return void
     */
    protected function extractCallbacks(&$args, &$list, &$path)
    {
        foreach ($args as $k => &$v) {
            if (is_array($v)) {
                if (sizeof($v) === 2) {
                    if (isset($v[0]) && is_object($v[0])) {
                        if (isset($v[1]) && is_string($v[1])) {
                            $id = ++$this->counter;
                            if ($this->persistentMode) {
                                $this->persistentCallbacks[$id] = $v;
                            } else {
                                $this->callbacks[$id] = $v;
                            }
                            $v = '';
                            $list[$id] = $path;
                            $list[$id][] = $k;
                            continue;
                        }
                    }
                }
                $path[] = $k;
                $this->extractCallbacks($v, $list, $path);
                array_pop($path);
            } elseif ($v instanceof \Closure) {
                $id = ++$this->counter;
                if ($this->persistentMode) {
                    $this->persistentCallbacks[$id] = $v;
                } else {
                    $this->callbacks[$id] = $v;
                }
                $v = '';
                $list[$id] = $path;
                $list[$id][] = $k;
            }
        }
    }

    /**
     * Sends a packet
     * @param  array $pct Data
     * @return void
     */
    protected function sendPacket($pct)
    {
        if (!$this->client) {
            return;
        }
        if (is_string($pct['method']) && ctype_digit($pct['method'])) {
            $pct['method'] = (int)$pct['method'];
        }
        $this->client->sendFrame(static::toJson($pct) . "\n");
    }

    /**
     * Encodes value into JSON
     * @param  mixed $m Value
     * @return this
     */
    public static function toJson($m)
    {
        return json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Calls a local method
     * @param  string $method Method name
     * @param  mixed ...$args Arguments
     * @return this
     */
    public function callLocal(...$args)
    {
        if (!sizeof($args)) {
            return $this;
        }
        $method = array_shift($args);
        $p = [
            'method' => $method,
            'arguments' => $args,
        ];
        $this->onPacket($p);
        return $this;
    }

    /**
     * Called when new packet is received
     * @param  array $pct Packet
     * @return void
     */
    public function onPacket($pct)
    {
        if ($this->cleaned) {
            return;
        }
        $m = isset($pct['method']) ? $pct['method'] : null;
        $args = isset($pct['arguments']) ? $pct['arguments'] : [];
        if (isset($pct['callbacks']) && is_array($pct['callbacks'])) {
            foreach ($pct['callbacks'] as $id => $path) {
                static::setPath($args, $path, [$this, 'remote_' . $id]);
            }
        }
        if (isset($pct['links']) && is_array($pct['links'])) {
            foreach ($pct['links'] as $link) {
                static::setPath($args, $link['to'], static::getPath($args, $link['from']));
            }
        }
        try {
            if (is_string($m)) {
                if (isset($this->localMethods[$m])) {
                    $this->localMethods[$m](...$args);
                } elseif (method_exists($this, $m . 'Method')) {
                    $func = [$this, $m . 'Method'];
                    $func(...$args);
                } else {
                    $this->handleException(new UndefinedMethodCalled(
                        'DNode: local method ' . json_encode($m) . ' does not exist. Packet: ' . json_encode($pct)
                    ));
                }
            } elseif (is_int($m)) {
                if (isset($this->callbacks[$m])) {
                    if (!$this->callbacks[$m](...$args)) {
                        unset($this->callbacks[$m]);
                    }
                } elseif (isset($this->persistentCallbacks[$m])) {
                    $this->persistentCallbacks[$m](...$args);
                } else {
                    $this->handleException(new UndefinedMethodCalled(
                        'DNode: local callback # ' . $m . ' is not registered. Packet: ' . json_encode($pct)
                    ));
                }
            } else {
                $this->handleException(new ProtocolError(
                    'DNode: \'method\' must be string or integer. Packet: ' . json_encode($pct)
                ));
            }
        } catch (\Throwable $exception) {
        }
    }

    /**
     * Sets value by materialized path
     * @param  array &$m
     * @param  array $path
     * @param  mixed $val
     * @return void
     */
    protected static function setPath(&$m, $path, $val)
    {
        foreach ($path as $p) {
            $m =& $m[$p];
        }
        $m = $val;
    }

    /**
     * Finds value by materialized path
     * @param  array &$m
     * @param  array $path
     * @return mixed Value
     */
    protected static function &getPath(&$m, $path)
    {
        foreach ($path as $p) {
            $m =& $m[$p];
        }
        return $m;
    }

    /**
     * Called when session is finished
     * @return void
     */
    public function onFinish()
    {
        $this->cleanup();
        parent::onFinish();
    }

    /**
     * Swipes internal structures
     * @return void
     */
    public function cleanup()
    {
        $this->cleaned = true;
        $this->remoteMethods = [];
        $this->localMethods = [];
        $this->persistentCallbacks = [];
        $this->callbacks = [];
    }

    /**
     * Magic __call method
     * @param  string $method Method name
     * @param  array $args Arguments
     * @throws UndefinedMethodCalled if method name not start from 'remote_'
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (strncmp($method, 'remote_', 7) === 0) {
            $this->callRemoteArray(substr($method, 7), $args);
        } elseif ($this->magicCallParent) {
            return parent::__call($method, $args);
        } else {
            throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
        }
    }

    /**
     * Called when new frame is received
     * @param string $data Frame's contents
     * @param integer $type Frame's type
     * @return void
     */
    public function onFrame($data, $type)
    {
        foreach (explode("\n", $data) as $pct) {
            if ($pct === '') {
                continue;
            }
            $this->onPacket(json_decode($pct, true));
        }
    }

    /**
     * Handler of the 'methods' method
     * @param  array $methods Associative array of methods
     * @return void
     */
    protected function methodsMethod($methods)
    {
        $this->remoteMethods = $methods;
    }
}
