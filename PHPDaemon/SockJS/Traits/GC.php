<?php
namespace PHPDaemon\SockJS\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Contains some base methods
 *
 * @package Libraries
 * @subpackage SockJS
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait GC {
	protected $maxBytesSent = 131072;
	protected $bytesSent = 0;
	protected $gc = false;
	public function gcCheck() {
		if ($this->maxBytesSent > 0 && !$this->gc && $this->bytesSent > $this->maxBytesSent) {
			$this->gc = true;
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->finish();
			});
		}
	}
	/**
	 * Output some data
	 * @param string $s String to out
	 * @param bool $flush
	 * @return boolean Success
	 */
	public function out($s, $flush = true) {
		$this->bytesSent += strlen($s);
		parent::out($s, $flush);
	}
}
