<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Jsonp extends Generic {
	use Traits\Request;
	protected $stage = 0;
	protected $frames = [];
	protected $timer;

	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->frames[] = $frame;
		}
		$this->delayedStop();
	}

	public function delayedStop() {
		Timer::setTimeout($this->timer, 0.15e6) || $this->timer = setTimeout(CallbackWrapper::wrap(function($timer) {
			$this->timer = true;
			$timer->free();
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				foreach ($this->frames as $frame) {
					$this->out($_GET['c'] . '(' . json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE). ");\r\n");
				}
				$this->finish();
			});
		}), 0.15e6);
	}

	public function onFinish() {
		$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
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
		$this->contentType('application/javascript');
		$this->noncache();
		if (!isset($_GET['c']) || !is_string($_GET['c']) || preg_match('~[^_\.a-zA-Z0-9]~', $_GET['c'])) {
			$this->header('400 Bad Request');
			return;
		}
		$this->appInstance->subscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
			$this->appInstance->publish('poll:' . $this->sessId, '', function($redis) {
				if ($redis->result === 0) {
					if (!$this->appInstance->beginSession($this->path, $this->sessId, $this->attrs->server)) {
						$this->header('404 Not Found');
						$this->finish();
					}
				}
			});
		});
		$this->sleep(30);
	}
}
