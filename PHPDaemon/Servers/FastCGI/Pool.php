<?php
namespace PHPDaemon\Servers\FastCGI;

use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;

/**
 * @package    NetworkServers
 * @subpackage Base
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\NetworkServer {

	/* Variables order
	 * @var string "GPC"
	 */
	public $variablesOrder;

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                  => 1,
			'auto-read-body-file'     => 1,
			'listen'                  => '127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
			'port'                    => 9000,
			'allowed-clients'         => '127.0.0.1',
			'send-file'               => 0,
			'send-file-dir'           => '/dev/shm',
			'send-file-prefix'        => 'fcgi-',
			'send-file-onlybycommand' => 0,
			'keepalive'               => new Time('0s'),
			'chunksize'               => new Size('8k'),
			'defaultcharset'          => 'utf-8',
			'upload-max-size'         => new Size(ini_get('upload_max_filesize')),
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
		}
		else {
			$this->variablesOrder = null;
		}

	}

}

