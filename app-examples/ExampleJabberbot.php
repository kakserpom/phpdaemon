<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class ExampleJabberbot extends AppInstance {
	public $xmppclient;
	public $xmppconn;
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'url' => 'xmpp://user:password@host/phpDaemon'
		);
	}
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->xmppclient = XMPPClient::getInstance();
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->xmppclient) {
			$this->xmppclient->onReady();
			$this->connect();
		}
	}
	
	public function connect() {
		$app = $this;
		$this->xmppclient->getConnection($this->config->url->value, function ($conn) use ($app) {
			$app->xmppconn = $conn;
			if ($conn->connected) {
				Daemon::log('Jabberbot connected at '.$this->config->url->value);
				$conn->presence('I\'m a robot.', 'chat');
				$conn->addEventHandler('message', function($msg) use ($conn) {
					Daemon::log('JabberBot: got message \''.$msg['body'].'\'');
					$conn->message($msg['from'], 'You just wrote: '.$msg['body']); // send the message back
				});
				$conn->addEventHandler('disconnect', function() use ($app) {
					$app->connect();
				});
			}
			else {
				Daemon::log('Jabberbot: unable to connect ('.$this->config->url->value.')');
			}
		});
	}
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($this->xmppclient) {
			$this->xmppclient->config = $this->config;
			return $this->xmppclient->onConfigUpdated();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		if ($this->xmppclient) {
			return $this->xmppclient->onShutdown();
		}
		return true;
	}
}
