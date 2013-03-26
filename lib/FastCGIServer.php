<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FastCGIServer extends NetworkServer {

	/* Variables order
	 * @var string "GPC"
	 */
	public $variablesOrder;
	
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                  => 1,
			'auto-read-body-file'     => 1,
			'listen'                  =>  '127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
			'port'      		      => 9000,
			'allowed-clients'         => '127.0.0.1',
			'send-file'               => 0,
			'send-file-dir'           => '/dev/shm',
			'send-file-prefix'        => 'fcgi-',
			'send-file-onlybycommand' => 0,
			'keepalive'               => new Daemon_ConfigEntryTime('0s'),
			'chunksize'               => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset'		=> 'utf-8',
			'upload-max-size'		=> new Daemon_ConfigEntrySize(ini_get('upload_max_filesize')),
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
	
}

