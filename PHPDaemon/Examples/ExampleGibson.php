<?php
/**
 * @package    Examples
 * @subpackage ExampleGibson
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
namespace PHPDaemon\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;

/**
 * Class ExampleGibson
 * @package PHPDaemon\Applications
 * For testing gideros functionality
 */
class ExampleGibson extends \PHPDaemon\Core\AppInstance {
	public $gibson;
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$this->gibson = \PHPDaemon\Clients\Gibson\Pool::getInstance();
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleGibsonRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleGibsonRequest($this, $upstream, $req);
	}
}

class ExampleGibsonRequest extends Generic {
	public $job;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		$job = $this->job = new \PHPDaemon\Core\ComplexJob(function ($job) { // called when job is done
			$this->wakeup(); // wake up the request immediately
			$job->keep(); // prevent from cleaning
		});

		if (isset($_GET['fill'])) {
			for ($i = 0; $i < 100; ++$i) {
				$this->appInstance->gibson->set(3600, 'key' . $i, 'val' . $i);
			}
		}

		$job('testquery', function ($jobname, $job) { // registering job named 'testquery'
			$this->appInstance->gibson->mget('key99', function($conn) use ($job, $jobname) {
				if ($conn->isFinal()) {
					$job->setResult($jobname, $conn->result);
				}
			});
		});

		$job(); // let the fun begin

		$this->sleep(1, true); // setting timeout*/
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		try {
			$this->header('Content-Type: text/html');
		} catch(\Exception $e) {}
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>Example with Gibson</title>
</head>
<body>
<?php
	if ($r = $this->job->getResult('testquery')) {
		echo '<h1>It works! Be happy! ;-)</h1>Result of query: <pre>';
		var_dump($r);
		echo '</pre>';
	} else {
		echo '<h1>Something went wrong! We have no result...</h1>';
	}
	echo '<br />Request (http) took: ' . round(microtime(TRUE) - $this->attrs->server['REQUEST_TIME_FLOAT'], 6);
?>
</body>
</html>
<?php
	}
}
