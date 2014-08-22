<?php
namespace PHPDaemon\Examples;

class ExamplePubSub extends \PHPDaemon\Core\AppInstance {
	public $sql;
	public $pubsub;

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$appInstance = $this; // a reference to this application instance for ExampleWebSocketRoute
		\PHPDaemon\Servers\WebSocket\Pool::getInstance()->addRoute('ExamplePubSub', function ($client) use ($appInstance) {
			return new ExamplePubSubWebSocketRoute($client, $appInstance);
		});
		$this->sql    = \PHPDaemon\Clients\MySQL\Pool::getInstance();
		$this->pubsub = new \PHPDaemon\PubSub\PubSub();
		$this->pubsub->addEvent('usersNum', \PHPDaemon\PubSub\PubSubEvent::init()
												  ->onActivation(function ($pubsub) use ($appInstance) {
													  \PHPDaemon\Core\Daemon::log('onActivation');
													  if (isset($pubsub->event)) {
														  \PHPDaemon\Core\Timer::setTimeout($pubsub->event, 0);
														  return;
													  }
													  $pubsub->event = setTimeout(function ($timer) use ($pubsub, $appInstance) {
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
														  \PHPDaemon\Core\Timer::cancelTimeout($pubsub->event);
													  }
												  })
		);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExamplePubSubTestPageRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExamplePubSubTestPageRequest($this, $upstream, $req);
	}
}

