<?php
namespace PHPDaemon\Examples;

use PHPDaemon\Clients\Redis\Pool;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Request\IRequestUpstream;

/**
 * @package    Examples
 * @subpackage Redis
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExampleWithRedis extends \PHPDaemon\Core\AppInstance {

	/**
	 * @var Pool
	 */
	public $redis;

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$this->redis = Pool::getInstance();

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
		return new ExampleWithRedisRequest($this, $upstream, $req);
	}

}

class ExampleWithRedisRequest extends Generic {

	/**
	 * @var $appInstance ExampleWithRedis
	 */
	public $appInstance;

	/**
	 * @var \PHPDaemon\Core\ComplexJob
	 */
	public $job;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		$job = $this->job = new \PHPDaemon\Core\ComplexJob(function ($job) { // called when job is done

			/**
			 * @var $job \PHPDaemon\Core\ComplexJob
			 */
			$job->keep(); // prevent cleaning up results
			$this->wakeup(); // wake up the request immediately

		});

		$this->appInstance->redis->lpush('mylist', microtime(true)); // just pushing something

		$job('testquery', function ($name, $job)  { // registering job named 'testquery'
			$this->appInstance->redis->lrange('mylist', 0, 10, function ($conn) use ($name, $job) { // calling lrange Redis command

				/**
				 * @var $job \PHPDaemon\Core\ComplexJob
				 */
				$job->setResult($name, $conn->result); // setting job result

			});
		});

		$job(); // let the fun begin

		$this->sleep(1, true); // setting timeout
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		try {
			$this->header('Content-Type: text/html');
		} catch (\Exception $e) {
		}
		?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
			<title>Example with Redis</title>
		</head>
		<body>
		<?php
		if ($r = $this->job->getResult('testquery')) {
			echo '<h1>It works! Be happy! ;-)</h1>Result of query: <pre>';
			var_dump($r);
			echo '</pre>';
		}
		else {
			echo '<h1>Something went wrong! We have no result.</h1>';
		}
		echo '<br />Request (http) took: ' . round(microtime(TRUE) - $this->attrs->server['REQUEST_TIME_FLOAT'], 6);
		?>
		</body>
		</html>
	<?php
	}

}
