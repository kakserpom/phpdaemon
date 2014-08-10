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
	protected $bytesSent = 0;
	protected $gc = false;
	public function gcCheck() {
		if (!($this->appInstance->config->gcmaxresponsesize->value > 0)) {
			return;
		}
		if (!$this->gc && $this->bytesSent > $this->appInstance->config->gcmaxresponsesize->value) {
			$this->gc = true;
			$this->stop();
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
