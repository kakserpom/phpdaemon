<?php
/*
Database credentials: add to phpd.conf:
Pool:\PHPDaemon\Clients\MySQL\Pool{
    enable 1;
    server 'tcp://user:password@127.0.0.1/dbname';
    privileged;
}
*/

namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
/**
 * @package    Examples
 * @subpackage MySQL
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleWithMySQL extends \PHPDaemon\Core\AppInstance {
	public $sql;

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */

	public function onReady() {
		$this->sql = \PHPDaemon\Clients\MySQL\Pool::getInstance();
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleWithMySQLRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleWithMySQLRequest($this, $upstream, $req);
	}

}

class ExampleWithMySQLRequest extends Generic {

	protected $job;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		$job = $this->job = new \PHPDaemon\Core\ComplexJob(function ($job) { // called when job is done

			$job->keep();
			$this->wakeup(); // wake up the request immediately

		});

		$job('select', function ($name, $job) { // registering job named 'select'

			$this->appInstance->sql->getConnection(function ($sql) use ($name, $job) {
				if (!$sql->isConnected()) {
					$job->setResult($name, null);
					return null;
				}
				$sql->query('SELECT 123, "string"', function ($sql, $success) use ($job, $name) {

					$job('showdbs', function ($name, $job) use ($sql) { // registering job named 'showdbs'
						$sql->query('SHOW DATABASES', function ($sql, $t) use ($job, $name) {
							$job->setResult($name, $sql->resultRows);
						});
					});
					$job->setResult($name, $sql->resultRows);
				});
				return null;
			});
		});

		$job(); // let the fun begin

		$this->sleep(5, true); // setting timeout
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

	?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
	<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		<title>Example with MySQL</title>
	</head>
	<body>
	<?php
	if ($r = $this->job->getResult('select')) {
		echo '<h1>It works! Be happy! ;-)</h1>Result of SELECT 123, "string": <pre>';
		var_dump($r);
		echo '</pre>';

		echo '<br />Result of SHOW DATABASES: <pre>';
		var_dump($this->job->getResult('showdbs'));
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
