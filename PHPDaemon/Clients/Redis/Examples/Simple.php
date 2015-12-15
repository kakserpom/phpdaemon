<?php
/**
 * `phpd.conf`
 * Clients\Redis\Examples\Simple {}
 */
namespace PHPDaemon\Clients\Redis\Examples;

/**
 * @package    NetworkClients
 * @subpackage RedisClientExample
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Simple extends \PHPDaemon\Core\AppInstance {
	/**
	 * @var Pool
	 */
	public $redis;

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$this->redis = \PHPDaemon\Clients\Redis\Pool::getInstance();

		/*$this->redis->eval("return {'a','b','c', {'d','e','f', {'g','h','i'}} }",0, function($redis) {
			Daemon::log(Debug::dump($redis->result));
		});*/

		$this->redis->subscribe('te3st', function($redis) {
			Daemon::log(Debug::dump($redis->result));
		});
		$this->redis->psubscribe('test*', function($redis) {
			Daemon::log(Debug::dump($redis->result));

		});
	}

	/**
	 * Creates Request.
	 * @param $req object Request.
	 * @param $upstream IRequestUpstream Upstream application instance.
	 * @return ExampleWithRedisRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new SimpleRequest($this, $upstream, $req);
	}
}
