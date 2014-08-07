<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class XhrStreaming extends Generic {
	use Traits\Request;
	protected $stage = 0;
	protected $fillerSent = false;
	protected $bytesSent = 0;
	protected $gc = false;

	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		if (!$this->fillerSent) {
			$this->out(str_repeat('h', 2048) . "\n");
			$this->fillerSent = true;
		}
		foreach ($frames as $frame) {
			$this->out($frame . "\n");
		}

		if (!$this->gc && $this->bytesSent > 128 * 1024) {
			$this->gc = true;
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->finish();
			});
		}
	}

	public function w8in($redis) {
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

	public function onFinish() {
		$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
		$this->appInstance->unsubscribe('w8in:' . $this->sessId, [$this, 'w8in']);
		parent::onFinish();
	}

	protected function poll() {
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->stage++ > 0) {
			$this->sleep(300);
			return;
		}
		$this->CORS();
		$this->contentType('application/json');
		$this->noncache();
		$this->acquire(function() {
			$this->appInstance->subscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->appInstance->publish('poll:' . $this->sessId, '', function($redis) {
					if ($redis->result > 0) {
						return;
					}
					if (!$this->appInstance->beginSession($this->path, $this->sessId, $this->attrs->server)) {
						$this->header('404 Not Found');
						$this->finish();
					}
				});
			});
		});
		$this->sleep(300);
	}
}
