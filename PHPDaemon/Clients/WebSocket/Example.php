<?php
namespace PHPDaemon\Clients\WebSocket;
use PHPDaemon\Core\AppInstance;

/**
 * @package    Clients
 * @subpackage WebSocket
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Example extends AppInstance {

	public $wsclient;

	public $wsconn;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'url'                 => 'tcp://echo.websocket.org:80/',
			'reconnect'           => 1,
			'wsclient-name' => ''
		];
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->wsclient = Pool::getInstance($this->config->wsclientname->value);
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->wsclient) {
			$this->wsclient->onReady();
			$this->connect();
		}
	}

	public function connect() {
		$this->wsclient->getConnection($this->config->url->value, function ($conn) {
			$this->wsconn = $conn;
			if ($conn->connected) {
				$conn->sendFrame('foobar');
				$conn->on('disconnect', function ($conn) {
					$this->log('Connection lost... Reconnect in ' . $this->config->reconnect->value . ' sec');
					$this->connect();
				})->on('frame', function ($conn, $frame) {
					$this->log('Got frame: ' . $frame);
				});
			}
			else {
				$this->log('Couldn\'t connect to ' . $this->config->url->value);
			}
		});
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		if ($this->wsclient) {
			return $this->wsclient->onShutdown();
		}
		return true;
	}
}

