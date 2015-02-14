<?php
namespace PHPDaemon\Clients\Redis\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;

class SimpleRequest extends Generic {
	/**
	 * @var $appInstance Simple
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
		$job = $this->job = new \PHPDaemon\Core\ComplexJob(function ($job) {
			// called when job is done

			// prevent cleaning up results
			$job->keep();

			// wake up the request immediately
			$this->wakeup();
		});

		// just pushing something
		$this->appInstance->redis->lpush('mylist', microtime(true));

		// registering job named 'testquery'
		$job('testquery', function ($name, $job)  {
			$this->appInstance->redis->lrange('mylist', 0, 10, function ($conn) use ($name, $job) {
				// calling lrange Redis command
				
				// setting job result
				$job->setResult($name, $conn->result);

			});
		});

		// let the fun begin
		$job();

		// setting timeout
		$this->sleep(1, true);
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		try {
			$this->header('Content-Type: text/html');
		} catch (\Exception $e) {}

?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title>Clients\Redis Simple example</title>
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
