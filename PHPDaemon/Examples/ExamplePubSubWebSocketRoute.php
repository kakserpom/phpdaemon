<?php
namespace PHPDaemon\Examples;

class ExamplePubSubWebSocketRoute extends ExampleWebSocketRoute {
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Called when new frame received.
	 * @param string  Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		$ws  = $this;
		$req = json_decode($data, true);
		if ($req['type'] === 'subscribe') {
			$eventName = $req['event'];
			$this->appInstance->pubsub->sub($req['event'], $this, function ($data) use ($ws, $eventName) {
				$ws->sendObject([
									'type'  => 'event',
									'event' => $eventName,
									'data'  => $data,
								]);
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