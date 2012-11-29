<?php
class ExamplePubSub extends AppInstance {
	public $sql;
	public $pubsub;
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$appInstance = $this; // a reference to this application instance for ExampleWebSocketRoute
		WebSocketServer::getInstance()->addRoute('ExamplePubSub', function ($client) use ($appInstance) {
			return new ExamplePubSubWebSocketRoute($client, $appInstance);
		});
		$this->sql = MySQLClient::getInstance();
		$this->pubsub = new PubSub;
		$this->pubsub->addEvent('usersNum', PubSubEvent::init()
			->onActivation(function($pubsub) use ($appInstance) {
				Daemon::log('onActivation');
				if (isset($pubsub->event)) {
					Timer::setTimeout($pubsub->event, 0);
					return;
				}
				$pubsub->event = setTimeout(function($timer) use ($pubsub, $appInstance) {
					$appInstance->sql->getConnection(function ($sql) use ($pubsub) {
						if (!$sql->connected) {
							return;
						}
						$sql->query('SELECT COUNT(*) `num` FROM `dle_users`', function ($sql, $success) use ($pubsub) {
							$pubsub->pub(sizeof($sql->resultRows) ? $sql->resultRows[0]['num'] : 'null');
						});
					});
					$timer->timeout(5e6); // 5 seconds
				}, 0);
			})
			->onDeactivation(function ($pubsub) {
				if (isset($pubsub->event)) {
					Timer::cancelTimeout($pubsub->event);
				}
			})
		);
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExamplePubSubTestPageRequest($this, $upstream, $req);
	}
}

class ExamplePubSubWebSocketRoute extends WebSocketRoute { 

	/**
	 * Called when new frame received.
	 * @param string Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		$ws = $this;
		$req = json_decode($data, true);
		if ($req['type'] === 'subscribe') {
			$eventName = $req['event'];
			$this->appInstance->pubsub->sub($req['event'], $this, function ($data) use ($ws, $eventName) {
				$ws->sendObject(array(
					'type' => 'event',
					'event' => $eventName,
					'data' => $data,
				));
			});	
		}
	}

	public function sendObject($obj) {
		$this->client->sendFrame(json_encode($obj), 'STRING');
	}	

	public function onFinish() {
		$this->appInstance->pubsub->unsubFromAll($this);
	}
}

class ExamplePubSubTestPageRequest extends HTTPRequest {

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
	ws = new WebSocket('ws://'+document.domain+':8047/ExamplePubSub');
	ws.onopen = function() {document.getElementById('log').innerHTML += 'WebSocket opened <br/>';}
 	ws.onmessage = function(e) {document.getElementById('log').innerHTML += 'WebSocket message: '+e.data+' <br/>';}
	ws.onclose = function() {document.getElementById('log').innerHTML += 'WebSocket closed <br/>';}
}
function subscribe() {
	ws.send('{"type":"subscribe","event":"usersNum"}');
}
</script>
<button onclick="create();">Create WebSocket</button>
<button onclick="subscribe();">Send subscribe</button>
<button onclick="ws.close();">Close WebSocket</button>
<div id="log" style="width:300px; height: 300px; border: 1px solid #999999; overflow:auto;"></div>
</body></html>
<?php
	}	
}

