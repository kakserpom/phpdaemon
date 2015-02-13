<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\CallbackWrapper;

/**
 * Easy wrapper for queue of eval's
 * 
 * @package    NetworkClients
 * @subpackage RedisClient
 * @author     Efimenko Dmitriy <ezheg89@gmail.com>
 *
 * Use exampe 1:
 * var $eval = $this->redis->meval($cb);
 * $eval->add('redis.call("set",KEYS[1],ARGV[1])', [$key1], [$arg1]);
 * $eval->add('redis.call("del",KEYS[1])', [$key2]);
 * $eval->add('redis.call("expireat",KEYS[1],ARGV[1])', $key1, $arg2);
 * $eval->exec();
 * 
 * Use exampe 2:
 * var $eval = $this->redis->meval($cb);
 * $eval('redis.call("set",KEYS[1],ARGV[1])', [$key1], [$arg1]);
 * $eval('redis.call("del",KEYS[1])', [$key2]);
 * $eval('redis.call("expireat",KEYS[1],ARGV[1])', $key1, $arg2);
 * $eval();
 *
 * Result request:
 * eval 'redis.call("set",KEYS[1],ARGV[1]);redis.call("del",KEYS[2]);redis.call("expireat",KEYS[1],ARGV[2])' 2 $key1 $key2 $arg1 $arg2
 */
class MultiEval {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $pool;

	protected $stack = [];

	protected $cachedParams = false;

	/**
	 * @var array Listeners
	 */
	public $listeners = [];

	/**
	 * Constructor
	 * @param callable $cb   Callback
	 * @param Pool     $pool Redis pool
	 */
	public function __construct($cb, $pool) {
		$this->pool = $pool;
		if ($cb !== null) {
			$this->addListener($cb);
		}
	}

	/**
	 * Adds listener
	 * @param callable $cb
	 */
	public function addListener($cb) {
		$this->listeners[] = CallbackWrapper::wrap($cb);
	}

	/**
	 * Adds eval command in stack
	 * @param string $cmd  Lua script
	 * @param mixed  $keys Keys
	 * @param mixed  $argv Arguments
	 */
	public function add($cmd, $keys = null, $argv = null) {
		if ($keys !== null) {
			if (is_scalar($keys)) {
				$keys = [(string) $keys];
			} else
			if (!is_array($keys)) {
				throw new \Exception("Keys must be an array or scalar");
			}
		}
		if ($argv !== null) {
			if (is_scalar($argv)) {
				$argv = [(string) $argv];
			} else
			if (!is_array($argv)) {
				throw new \Exception("Argv must be an array or scalar");
			}
		}

		$this->cachedParams = false;
		$this->stack[] = [$cmd, $keys, $argv];
	}

	/**
	 * Clean up
	 */
	public function cleanup() {
		$this->listeners = [];
	}

	/**
	 * Return params for eval command
	 * @return array
	 */
	public function getParams() {
		if ($this->cachedParams) {
			return $this->cachedParams;
		}

		$CMDS = [];
		$KEYS = [];
		$ARGV = [];
		$KEYNUM = 0;
		$ARGNUM = 0;

		foreach ($this->stack as $part) {
			list($cmd,$keys,$argv) = $part;

			if (!empty($keys)) {
				$cmd = preg_replace_callback('~KEYS\[(\d+)\]~', function($m) use (&$KEYS, &$KEYNUM, $keys) {
					$key = $keys[ $m[1] - 1 ];
					if (!isset($KEYS[$key])) {
						$KEYS[$key] = ++$KEYNUM;
					}
					return 'KEYS[' . $KEYS[$key] . ']';
				}, $cmd);
			}

			if (!empty($argv)) {
				$cmd = preg_replace_callback('~ARGV\[(\d+)\]~', function($m) use (&$ARGV, &$ARGNUM, $argv) {
					$arg = $argv[ $m[1] - 1 ];
					if (!isset($ARGV[$arg])) {
						$ARGV[$arg] = ++$ARGNUM;
					}
					return 'ARGV[' . $ARGV[$arg] . ']';
				}, $cmd);
			}

			$CMDS[] = $cmd;
		}

		return $this->cachedParams = array_merge(
			[implode(';', $CMDS), count($KEYS)],
			array_keys($KEYS),
			array_keys($ARGV)
		);
	}

	/**
	 * Runs the stack of commands
	 */
	public function execute() {
		if (!count($this->stack)) {
			foreach ($this->listeners as $cb) {
				call_user_func($cb, $this->pool);
			}
			return;
		}

		$params = $this->getParams();
		$params[] = function($redis) {
			foreach ($this->listeners as $cb) {
				call_user_func($cb, $redis);
			}
		};

		call_user_func_array([$this->pool, 'eval'], $params);
	}

	/**
	 * Adds eval command or calls execute() method
	 * @return void
	 */
	public function __invoke() {
		if (func_num_args() === 0) {
			$this->execute();
			return;
		}
		call_user_func_array([$this, 'add'], func_get_args());
	}
}
