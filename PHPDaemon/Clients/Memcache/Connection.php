<?php
namespace PHPDaemon\Clients\Memcache;

use PHPDaemon\Network\ClientConnection;

/**
 * @package    Network clients
 * @subpackage MemcacheClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * DESCR
	 */
	const STATE_DATA = 1;
	
	/**
	 * @var mixed current result
	 */
	public $result;
	
	/**
	 * @var string flags of incoming value
	 */
	public $valueFlags;
	
	/**
	 * @var integer length of incoming value
	 */
	public $valueLength;
	
	/**
	 * @var string error message
	 */
	public $error;
	
	/**
	 * @var string current incoming key
	 */
	public $key;
	
	/**
	 * @var string
	 */
	protected $EOL = "\r\n";

	/**
	 * @var integer
	 */
	protected $maxQueue = 10;
	
	/**
	 * Called when new data received
	 * @return void
	 */
	protected function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			while (($l = $this->readLine()) !== null) {
				$e = explode(' ', $l);

				if ($e[0] === 'VALUE') {
					$this->key         = $e[1];
					$this->valueFlags  = $e[2];
					$this->valueLength = $e[3];
					$this->result      = '';
					$this->setWatermark($this->valueLength);
					$this->state = self::STATE_DATA;
					break;
				}
				elseif ($e[0] === 'STAT') {
					if ($this->result === null) {
						$this->result = [];
					}

					$this->result[$e[1]] = $e[2];
				}
				elseif (
						($e[0] === 'STORED')
						|| ($e[0] === 'END')
						|| ($e[0] === 'DELETED')
						|| ($e[0] === 'ERROR')
						|| ($e[0] === 'CLIENT_ERROR')
						|| ($e[0] === 'SERVER_ERROR')
				) {
					if ($e[0] !== 'END') {
						$this->result = FALSE;
						$this->error  = isset($e[1]) ? $e[1] : null;
					}
					$this->onResponse->executeOne($this);
					$this->checkFree();
					$this->result = null;
				}
			}
		}

		if ($this->state === self::STATE_DATA) {
			if (false === ($this->result = $this->readExact($this->valueLength))) {
				return; //we do not have a whole packet
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(1);
			goto start;
		}
	}
}
