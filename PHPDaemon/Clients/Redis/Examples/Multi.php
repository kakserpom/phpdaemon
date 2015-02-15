<?php
/**
 * `phpd.conf`
 * Clients\Redis\Examples\Multi {}
 */
namespace PHPDaemon\Clients\Redis\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkClients
 * @subpackage RedisClientExample
 * @author     Efimenko Dmitriy <ezheg89@gmail.com>
 */
class Multi extends \PHPDaemon\Core\AppInstance {
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

		$this->redis->multi(function($multi) {
			// "OK"
			D('start multi: ' . $multi->result);

			$multi->set('test1', 'value1', function($redis) use ($multi) {
				// "QUEUED"
				D('in multi 1: ' . $redis->result);

				$this->redis->set('test1', 'value1-new', function($redis) {
					// "OK", not "QUEUED"
					D('out multi 1: ' . $redis->result);
				});

				setTimeout(function($timer) use ($multi) {
					// "QUEUED"
					$multi->set('test2', 'value2', function($redis) use ($multi) {
						D('in multi 2: ' . $redis->result);

						$multi->exec(function($redis) {
							D('exec');
							D($redis->result);
						});
					});
					$timer->free();
				}, 2e5);
			});
		});
	}
}
