<?php

/**
 * @package NetworkServer
 * @subpackage HTTPServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTPServer extends NetworkServer {

	public $variablesOrder;
	public $WS; // WebSocketServer

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// default port
			'port' => 80,
			// log events
			'log-events' => 0,
			// log queue
			'log-queue' => 0,
			// @todo add description strings
			'send-file' => 0,
			'send-file-dir' => '/dev/shm',
			'send-file-prefix' => 'http-',
			'send-file-onlybycommand' => 0,
			// expose your soft by X-Powered-By string
			'expose' => 1,
			// @todo add description strings
			'keepalive' => new Daemon_ConfigEntryTime('0s'),
			'chunksize' => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset' => 'utf-8',
			// disabled by default
			'enable'     => 0,
			'wss-name' => '',
			//'responder' => default app
		);
	}
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		if (
			($order = ini_get('request_order')) 
			|| ($order = ini_get('variables_order'))
		) {
			$this->variablesOrder = $order;
		} else {
			$this->variablesOrder = null;
		}
		
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		parent::onReady();
		$this->WS = WebSocketServer::getInstance($this->config->wssname->value, false);
	}
}
