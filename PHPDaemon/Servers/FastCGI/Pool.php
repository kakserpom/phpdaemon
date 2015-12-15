<?php
namespace PHPDaemon\Servers\FastCGI;

use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;

/**
 * @package    NetworkServers
 * @subpackage Base
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Server {

	/** 
	 * @var string "GPC" Variables order
	 */
	public $variablesOrder;

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen'                  => 'tcp://127.0.0.1,unix:///tmp/phpdaemon.fcgi.sock',

			/* [integer] Listen port */
			'port'                    => 9000,

			/* [boolean] Read request body from the file given in REQUEST_BODY_FILE parameter */
			'auto-read-body-file'     => 1,

			/* [string] Allowed clients ip list */
			'allowed-clients'         => '127.0.0.1',

			/* [boolean] Enable X-Sendfile? */
			'send-file'               => 0,

			/* [string] Directory for X-Sendfile */
			'send-file-dir'           => '/dev/shm',

			/* [string] Prefix for files used for X-Sendfile */
			'send-file-prefix'        => 'fcgi-',

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

			/* [Size] Maximum uploading file size. */
			'upload-max-size'         => new Size(ini_get('upload_max_filesize')),
		];
	}

	/**
	 * Called when worker is going to update configuration
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
}
