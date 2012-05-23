<?php

/**
 * @package Applications
 * @subpackage FastCGI
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FastCGI extends AsyncServer {

	protected $initialLowMark  = 8;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	protected $queuedReads     = true;

	private $variablesOrder;

	const FCGI_BEGIN_REQUEST     = 1;
	const FCGI_ABORT_REQUEST     = 2;
	const FCGI_END_REQUEST       = 3;
	const FCGI_PARAMS            = 4;
	const FCGI_STDIN             = 5;
	const FCGI_STDOUT            = 6;
	const FCGI_STDERR            = 7;
	const FCGI_DATA              = 8;
	const FCGI_GET_VALUES        = 9;
	const FCGI_GET_VALUES_RESULT = 10;
	const FCGI_UNKNOWN_TYPE      = 11;
	
	const FCGI_RESPONDER         = 1;
	const FCGI_AUTHORIZER        = 2;
	const FCGI_FILTER            = 3;
	
	private static $roles = array(
		self::FCGI_RESPONDER         => 'FCGI_RESPONDER',
		self::FCGI_AUTHORIZER        => 'FCGI_AUTHORIZER',
		self::FCGI_FILTER            => 'FCGI_FILTER',
	);

	private static $requestTypes = array(
		self::FCGI_BEGIN_REQUEST     => 'FCGI_BEGIN_REQUEST',
		self::FCGI_ABORT_REQUEST     => 'FCGI_ABORT_REQUEST',
		self::FCGI_END_REQUEST       => 'FCGI_END_REQUEST',
		self::FCGI_PARAMS            => 'FCGI_PARAMS',
		self::FCGI_STDIN             => 'FCGI_STDIN',
		self::FCGI_STDOUT            => 'FCGI_STDOUT',
		self::FCGI_STDERR            => 'FCGI_STDERR',
		self::FCGI_DATA              => 'FCGI_DATA',
		self::FCGI_GET_VALUES        => 'FCGI_GET_VALUES',
		self::FCGI_GET_VALUES_RESULT => 'FCGI_GET_VALUES_RESULT',
		self::FCGI_UNKNOWN_TYPE      => 'FCGI_UNKNOWN_TYPE',
	);

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                  => 1,
			'auto-read-body-file'     => 1,
			'listen'                  =>  'tcp://127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
			'listen-port'             => 9000,
			'allowed-clients'         => '127.0.0.1',
			'send-file'               => 0,
			'send-file-dir'           => '/dev/shm',
			'send-file-prefix'        => 'fcgi-',
			'send-file-onlybycommand' => 0,
			'keepalive'               => new Daemon_ConfigEntryTime('0s'),
			'chunksize'               => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset'		=> 'utf-8',
			// disabled by default
			'enable'                  => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable) {
			if (
				($order = ini_get('request_order')) 
				|| ($order = ini_get('variables_order'))
			) {
				$this->variablesOrder = $order;
			} else {
				$this->variablesOrder = null;
			}
			
			$this->allowedClients = explode(',', $this->config->allowedclients->value);

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}
	/**
	 * Called when remote host is trying to establish the connection.
	 * @return boolean If true then we can accept new connections, else we can't.
	 */
	public function checkAccept($stream, $event, $arg) {
		if (Daemon::$process->reload) {
			return false;
		}

		return Daemon::$config->maxconcurrentrequestsperworker->value >= sizeof($this->queue);
	}
	

	/**
	 * Reads data from the connection's buffer.
	 * @param integer Connection's ID.
	 * @return void
	 */
	public function readConn($connId) {
	
	}
	
}
