<?php
namespace PHPDaemon\Servers\HTTP;

use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;

/**
 * @package    NetworkServer
 * @subpackage HTTPServer
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Server {

	/** Variables order
	 * @var string "GPC"
	 */
	public $variablesOrder;

	/** WebSocketServer instance
	 * @var WebSocketServer
	 */
	public $WS;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Default servers
			 * @var string|array
			 */
			'listen'                  => 'tcp://0.0.0.0',

			/**
			 * Default port
			 * @var integer
			 */
			'port'                    => 80,

			/**
			 * Enable X-Sendfile?
			 * @var boolean
			 */
			'send-file'               => 0,

			/**
			 * Directory for X-Sendfile
			 * @var string
			 */
			'send-file-dir'           => '/dev/shm',

			/**
			 * Prefix for files used for X-Sendfile
			 * @var string|array
			 */
			'send-file-prefix'        => 'http-',

			/**
			 * Use X-Sendfile only if server['USE_SENDFILE'] provided.
			 * @var boolean
			 */
			'send-file-onlybycommand' => 0,

			/**
			 * Expose PHPDaemon version by X-Powered-By Header
			 * @var boolean
			 */
			'expose'                  => 1,

			/**
			 * Keepalive time
			 * @var time
			 */
			'keepalive'               => new Time('0s'),

			/**
			 * Chunk size
			 * @var size
			 */
			'chunksize'               => new Size('8k'),

			/**
			 * Use X-Sendfile only if server['USE_SENDFILE'] provided.
			 * @var string|array
			 */
			'defaultcharset'          => 'utf-8',

			/**
			 * Related WebSocketServer instance name
			 * @var string
			 */
			'wss-name'                => '',

			/**
			 * Related FlashPolicyServer instance name
			 * @var string
			 */
			'fps-name'                => '',

			/**
			 * Maximum uploading file size.
			 * @var size
			 */
			'upload-max-size'         => new Size(ini_get('upload_max_filesize')),

			/**
			 * Reponder application (if you do not want to use AppResolver)
			 * @var string
			 */
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
		$this->WS = Pool::getInstance($this->config->wssname->value, false);
	}
}
