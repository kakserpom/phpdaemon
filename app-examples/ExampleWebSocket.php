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
