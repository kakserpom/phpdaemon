<?php

/**
 * @package Examples
 * @subpackage WebSocket
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleWebSocket extends AppInstance {
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$appInstance = $this; // a reference to this application instance for ExampleWebSocketRoute
		// URI /exampleApp should be handled by ExampleWebSocketRoute
		WebSocketServer::getInstance()->addRoute('exampleApp', function ($client) use ($appInstance) {
			return new ExampleWebSocketRoute($client, $appInstance);
		});
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleWebSocketTestPageRequest($this, $upstream, $req);
	}
}

class ExampleWebSocketRoute extends WebSocketRoute { 

	/**
	 * Called when new frame received.
	 * @param string Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		if ($data === 'ping') {
			$this->client->sendFrame('pong', 'STRING',
				function($client) { // optional. called when the frame is transmitted to the client
					Daemon::log('ExampleWebSocket: \'pong\' received by client.');
				}
			);
  		}
	}
	
}



class ExampleWebSocketTestPageRequest extends HTTPRequest {

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('Content-Type: text/html');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>WebSocket test page</title>
</head>
<body>
<script type="text/javascript">
function create() {
	// Example
	ws = new WebSocket('ws://'+document.domain+':8047/exampleApp');
	ws.onopen = function() {document.getElementById('log').innerHTML += 'WebSocket opened <br/>';}
 	ws.onmessage = function(e) {document.getElementById('log').innerHTML += 'WebSocket message: '+e.data+' <br/>';}
	ws.onclose = function() {document.getElementById('log').innerHTML += 'WebSocket closed <br/>';}
}
</script>
<button onclick="create();">Create WebSocket</button>
<button onclick="ws.send('ping');">Send ping</button>
<button onclick="ws.close();">Close WebSocket</button>
<div id="log" style="width:300px; height: 300px; border: 1px solid #999999; overflow:auto;"></div>
</body></html>
<?php
	}
	
}
