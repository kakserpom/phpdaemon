<?php
/**
 * `phpd.conf`
 * Clients\Redis\Examples\Scan {}
 */
namespace PHPDaemon\Clients\Redis\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkClients
 * @subpackage RedisClientExample
 * @author     Efimenko Dmitriy <ezheg89@gmail.com>
 */
class Scan extends \PHPDaemon\Core\AppInstance {
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

		$params = [];
		foreach (range(0, 100) as $i) {
			$params[] = 'myset' . $i;
			$params[] = 'value' . $i;
		}
		$params[] = function($redis) {
			$params = [function($redis) {
				D('Count: ' . count($redis->result[1]) . '; Next: ' . $redis->result[0]);
			}];
			
			$cbEnd = function($redis, $scan) {
				D('Full scan end!');
			};

			// test 1
			// call_user_func_array([$this->redis, 'scan'], $params);

			// test 2
			$this->redis->autoscan('scan', $params, $cbEnd, 50);
		};
		call_user_func_array([$this->redis, 'mset'], $params);
	}
}
