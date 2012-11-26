<?php

/**
 * @package Examples
 * @subpackage MySQL
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleWithMySQL extends AppInstance {
	public $sql;
	
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */

	public function onReady() {
		$this->sql = MySQLClient::getInstance();
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		
		return new ExampleWithMySQLRequest($this, $upstream, $req);
	}
	
}

class ExampleWithMySQLRequest extends HTTPRequest {

	public $job;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		$req = $this;
		
		$job = $this->job = new ComplexJob(function() use ($req) { // called when job is done

			$req->wakeup(); // wake up the request immediately

		});
		
		$job('showvar', function($name, $job) use ($req) { // registering job named 'showvar'
		
			$req->appInstance->sql->getConnection(function($sql) use ($name, $job) {
				if (!$sql->connected) {
					return $job->setResult($name, null);
				}
				$sql->query('SHOW VARIABLES', function($sql, $success) use ($job, $name) {
					
					$job('showdbs', function($name, $job) use ($sql) { // registering job named 'showdbs'
						$sql->query('SHOW DATABASES', function($sql, $t) use ($job, $name) {
							$job->setResult($name, $sql->resultRows);
						});
					});				
					$job->setResult($name, $sql->resultRows);
				});
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
		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}
			
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Example with MySQL</title>
</head>
<body>
<?php
if ($r = $this->job->getResult('showvar')) {
	echo '<h1>It works! Be happy! ;-)</h1>Result of SHOW VARIABLES: <pre>';
	var_dump(array_slice($r, 0, 5));
	echo '</pre>';

	echo '<br />Result of SHOW DATABASES: <pre>';
	var_dump($this->job->getResult('showdbs'));
	echo '</pre>';

} else {
	echo '<h1>Something went wrong! We have no result.</h1>';
}
echo '<br />Request (http) took: '.round(microtime(TRUE) - $this->attrs->server['REQUEST_TIME_FLOAT'],6);
?>
</body>
</html>
<?php
	}
	
}
