<?php
/**
 * `phpd.conf`
 * Clients\Redis\Examples\Iterator {}
 */
namespace PHPDaemon\Clients\Redis\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkClients
 * @subpackage RedisClientExample
 * @author     Efimenko Dmitriy <ezheg89@gmail.com>
 */
class Iterator extends \PHPDaemon\Core\AppInstance {
	/**
	 * @var Pool
	 */
	public $redis;

	/**
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
		$this->redis = \PHPDaemon\Clients\Redis\Pool::getInstance();

		$this->redis->hmset('myset', 'field1', 'value1', 'field2', 'value2', 'field3', 'value3', 'field4', 'value4', 'field5', 'value5', function($redis) {
			$this->redis->hgetall('myset', function($redis) {
				D('TEST 1: HGETALL');
				foreach ($redis as $key => $value) {
					D($key . ' - ' . $value);
				}
				D($redis->assoc);
			});

			$this->redis->hmget('myset', 'field2', 'field4', function($redis) {
				D('TEST 2: HMGET');
				foreach ($redis as $key => $value) {
					D($key . ' - ' . $value);
				}
				D($redis->assoc);
			});
		});

		$this->redis->zadd('myzset', 100, 'one', 150, 'two', 325, 'three', function($redis) {
			$this->redis->zrange('myzset', 0, -1, function($redis) {
				D('TEST 3: ZRANGE');
				foreach ($redis as $key => $value) {
					D($key . ' - ' . $value);
				}
				D($redis->assoc);
			});

			$this->redis->zrange('myzset', 0, -1, 'WITHSCORES', function($redis) {
				D('TEST 4: ZRANGE WITHSCORES');
				foreach ($redis as $key => $value) {
					D($key . ' - ' . $value);
				}
				D($redis->assoc);
			});
		});

		$this->redis->subscribe('mysub', function($redis) {
			D('TEST 5: SUB & PUB');
			foreach ($redis as $key => $value) {
				D($key . ' - ' . $value);
			}
			D($redis->channel, $redis->msg, $redis->assoc);
		});

		$this->redis->publish('mysub', 'Test message!');
	}
}
