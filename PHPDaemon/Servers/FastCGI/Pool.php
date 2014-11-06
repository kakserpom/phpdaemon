<?php
namespace PHPDaemon\Servers\FastCGI;

use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;

/**
 * @package    NetworkServers
 * @subpackage Base
 * @author     Zorin Vasily <maintainer@daemon.io>
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
			/* [boolean] @todo */
			'expose'                  => 1,

			/* [boolean] @todo */
			'auto-read-body-file'     => 1,

			/* [string|array] Listen addresses */
			'listen'                  => 'tcp://127.0.0.1,unix:///tmp/phpdaemon.fcgi.sock',

			/* [integer] Listen port */
			'port'                    => 9000,

			/* [string] @todo */
			'allowed-clients'         => '127.0.0.1',

			/* [boolean] @todo */
			'send-file'               => 0,

			/* [string] @todo */
			'send-file-dir'           => '/dev/shm',

			/* [string] @todo */
			'send-file-prefix'        => 'fcgi-',

			/* [boolean] @todo */
			'send-file-onlybycommand' => 0,

			/* [Time] @todo */
			'keepalive'               => new Time('0s'),

			/* [Size] @todo */
			'chunksize'               => new Size('8k'),

			/* [string] @todo */
			'defaultcharset'          => 'utf-8',

			/* [Size] @todo */
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
