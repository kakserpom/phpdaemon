<?php
namespace PHPDaemon\Examples;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\DNode\DNode;
/**
 * @package    Examples
 * @subpackage WebSocket
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleDNode extends \PHPDaemon\Core\AppInstance {
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$appInstance = $this; // a reference to this application instance for ExampleWebSocketRoute
		// URI /exampleApp should be handled by ExampleWebSocketRoute
		\PHPDaemon\Servers\WebSocket\Pool::getInstance()->addRoute('exampleDNode', function ($client) use ($appInstance) {
			return new ExampleDNodeRoute($client, $appInstance);
		});
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleDNodeTestPageRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleDNodeTestPageRequest($this, $upstream, $req);
	}
}

class ExampleDNodeRoute extends \PHPDaemon\WebSocket\Route {
	use DNode;
	public function onHandshake() {

		$this->defineLocalMethods([
			'serverTest' => function() {
				$this->callRemote('clientTest', 'foobar', function() {
					Daemon::log('callback called');
				});
			},
		]);
		$this->onFrame('{"method":"methods","arguments":[{"clientTest":{}}],"callbacks":{"1":[0,"clientTest"]},"links":[]} ', 'STRING');
		parent::onHandshake();
		
	}


	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean Handled?
	 */
	public function handleException($e) {
		$this->client->sendFrame('pong from exception: '.get_class($e));
		return true;
	}
}

class ExampleDNodeTestPageRequest extends Generic {

/**
 * Called when request iterated.
 * @return integer Status.
 */
public function run() {
$this->header('Content-Type: text/html');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>WebSocket test page</title>
</head>
<body onload="create();">
<script type="text/javascript">
	function create() {
		// Example
		ws = new WebSocket('ws://'+document.domain+':8047/exampleDNode');
		ws.onopen = function () {document.getElementById('log').innerHTML += 'WebSocket opened <br/>';}
		ws.onmessage = function (e) {document.getElementById('log').innerHTML += 'WebSocket message: '+e.data+' <br/>';}
		ws.onclose = function () {document.getElementById('log').innerHTML += 'WebSocket closed <br/>';}
	}
</script>
<button onclick="create();">Create WebSocket</button>
<button onclick="ws.send('ping');">Send ping</button>
<button onclick="ws.close();">Close WebSocket</button>
<div id="log" style="width:300px; height: 300px; border: 1px solid #999999; overflow:auto;"></div>
</body>
</html>
<?php
}

}
