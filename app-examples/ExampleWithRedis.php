<?php

/**
 * @package Examples
 * @subpackage Redis
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleWithRedis extends AppInstance {

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */

	public function onReady() {
		$this->redis = Daemon::$appResolver->getInstanceByAppName('RedisClient');
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleWithRedisRequest($this, $upstream, $req);
	}
	
}

class ExampleWithRedisRequest extends HTTPRequest {

	public $stime;
	public $queryResult;
	public $sql;
	public $runstate = 0;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$req = $this;
		$this->stime = microtime(true);
		
		$job = $this->job = new ComplexJob(function() use ($req) { // called when job is done

			$req->wakeup(); // wake up the request immediately

		});
		
		$this->appInstance->redis->rpush('mylist', microtime(true)); // just pushing something
		
		$job('testquery', function($name, $job) use ($req) { // registering job named 'testquery'
		
			$req->appInstance->redis->lrange('mylist', 0, 10, function($redis) use ($name, $job) { // calling lrange Redis command
				
				$job->setResult($name, $redis->result); 
				
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
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Example with Redis</title>
</head>
<body>
<?php
if ($r = $this->job->getResult('testquery')) {
	echo '<h1>It works! Be happy! ;-)</h1>Result of query: <pre>';
	var_dump($r);
	echo '</pre>';
} else {
	echo '<h1>Something went wrong! We have no result.</h1>';
}
echo '<br />Request (http) took: '.round(microtime(TRUE)-$this->stime,6);
?>
</body>
</html>
<?php
	}
	
}
