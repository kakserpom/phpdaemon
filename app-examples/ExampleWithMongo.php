<?php

/**
 * @package Examples
 * @subpackage Mongo
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleWithMongo extends AppInstance {	
	public $mongo;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->mongo = MongoClientAsync::getInstance(
			array('maxconnperserv' => 100)
		);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleWithMongoRequest($this, $upstream, $req);
	}
	
}

class ExampleWithMongoRequest extends HTTPRequest {

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
		
		$collection = $this->appInstance->mongo->{'testdb.testcollection'};
		$collection->insert(array('a' => microtime(true))); // just pushing something
		
		$job('testquery', function($name, $job) use ($collection) { // registering job named 'testquery'
		
		 	$collection->findOne(function($result) use ($name, $job) { // calling Mongo findOne
				
				$job->setResult($name, $result); 
				
			}, array('sort' => array('$natural' => -1)));
		});
		
		$job(); // let the fun begin
		
		$this->sleep(1, true); // setting timeout
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
<title>Example with Mongo</title>
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
echo '<br />Request (http) took: '.round(microtime(TRUE) - $this->attrs->server['REQUEST_TIME_FLOAT'],6);
?>
</body>
</html>
<?php
	}
	
}
