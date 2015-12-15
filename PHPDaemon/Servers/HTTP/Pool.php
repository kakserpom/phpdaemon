<?php
namespace PHPDaemon\Servers\HTTP;

use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;

/**
 * @package    NetworkServer
 * @subpackage HTTPServer
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Server {

	/**
	 * @var string Variables order "GPC"
	 */
	public $variablesOrder;

	/**
	 * @var WebSocketServer WebSocketServer instance
	 */
	public $WS;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen'                  => 'tcp://0.0.0.0',

			/* [integer] Listen port */
			'port'                    => 80,

			/* [boolean] Enable X-Sendfile? */
			'send-file'               => 0,

			/* [string] Directory for X-Sendfile */
			'send-file-dir'           => '/dev/shm',

			/* [string] Prefix for files used for X-Sendfile */
			'send-file-prefix'        => 'http-',

			/* [boolean] Use X-Sendfile only if server['USE_SENDFILE'] provided. */
			'send-file-onlybycommand' => 0,

			/* [boolean] Expose PHPDaemon version by X-Powered-By Header */
			'expose'                  => 1,

			/* [Time] Keepalive time */
			'keepalive'               => new Time('0s'),

			/* [Size] Chunk size */
			'chunksize'               => new Size('8k'),

			/* [string] Default charset */
			'defaultcharset'          => 'utf-8',

			/* [string] Related WebSocketServer instance name */
			'wss-name'                => '',

			/* [string] Related FlashPolicyServer instance name */
			'fps-name'                => '',

			/* [Size] Maximum uploading file size. */
			'upload-max-size'         => new Size(ini_get('upload_max_filesize')),

			/* [string] Reponder application (if you do not want to use AppResolver) */
			'responder'               => null,
		];
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
		}
		else {
			$this->variablesOrder = null;
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		parent::onReady();
		$this->WS = \PHPDaemon\Servers\WebSocket\Pool::getInstance($this->config->wssname->value, false);
	}
}
