<?php

/**
 * @package Examples
 * @subpackage Asterisk
 *
 * @author TyShkan <denis@tyshkan.ru>
 */
class ExampleAsteriskClient extends AppInstance {
	
	public $asteriskclient;
	
	public $asteriskconn;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'url' => 'tcp://user:password@localhost:5038',
			'reconnect' => 1,
			'asteriskclient-name' => ''
		];
	}
	
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->asteriskclient = AsteriskClient::getInstance($this->config->asteriskclientname->value);
		}
	}
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->asteriskclient) {
			$this->asteriskclient->onReady();
			$this->connect();
		}
	}
	
	public function connect() {
		$this->asteriskclient->getConnection($this->config->url->value, function ($conn) {
			$this->asteriskconn = $conn;
			if ($conn->connected) {
				$conn->bind('disconnect', function($conn) {
					Daemon::log('Connection lost... Reconnect in ' . $this->config->reconnect->value . ' sec');			
					$this->connect();
				});
			} else {
				Daemon::log(get_class($this).': couldn\'t connect to '.$this->config->url->value);
			}
		});
	}
	
	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		if ($this->asteriskclient) {
			return $this->asteriskclient->onShutdown();
		}		
		return true;
	}	
}

