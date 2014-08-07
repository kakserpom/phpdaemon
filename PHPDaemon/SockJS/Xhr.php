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

class Xhr extends Generic {
	use Traits\Request;
	protected $stage = 0;
	protected $frames = [];
	protected $timer;

	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->frames[] = $frame;
		}
		$this->delayedStop();
	}

	public function delayedStop() {
		Timer::setTimeout($this->timer, 0.15e6) || $this->timer = setTimeout(function($timer) {
			$this->timer = true;
			$timer->free();
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->out(json_encode($this->frames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				$this->finish();
			});
		}, 0.15e6);
	}

	public function onFinish() {
		//$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
		parent::onFinish();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->stage++ > 0) {
			return;
		}
		$this->CORS();
		$this->contentType('application/json');
		$this->noncache();
		$this->appInstance->subscribe('s2c:' . $this->sessId, [$this, 's2c']);
		$this->sleep(30);
	}
}
