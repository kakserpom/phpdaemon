<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;
use PHPDaemon\Core\CallbackWrapper;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Lock {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $pool;
	protected $timeout;
	protected $token;
	protected $key;

	/**
	 * Constructor
	 * @param string  $key
	 * @param integer $timeout
	 * @param Pool    $pool
	 */
	public function __construct($key, $timeout, $pool) {
		$this->pool = $pool;
		$this->timeout = $timeout;
		$this->key = $key;
	}

	/**
	 * @TODO
	 * @param  integer $timeout
	 * @return this
	 */
	public function timeout($timeout) {
		$this->timeout = $timeout;
		return $this;
	}

	/**
	 * @TODO
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return this
	 */
	public function acquire($cb) {
		Crypt::randomString(16, null, function($token) use ($cb) {
			$this->token = $token;
			$this->pool->set($this->key, $this->token, 'NX', 'EX', $this->timeout, function($redis) use ($cb) {
				call_user_func($cb, $this, $redis->result === 'OK', $redis);
			});
		});
		return $this;
	}

	/**
	 * @TODO
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return this
	 */
	public function release($cb = null) {		
		$this->pool->eval('if redis.call("get",KEYS[1]) == ARGV[1] then return redis.call("del",KEYS[1]) else return 0 end',
		 1, $this->key, $this->token, function($redis) use ($cb) {
		 	if ($cb !== null) {
		 		call_user_func($cb, $this, $redis->result, $redis);
		 	}
		});
		return $this;
	}
}
