<?php
namespace PHPDaemon\Applications;

/**
 * Class WebSocketOverCOMETSession
 * @package PHPDaemon\Applications
 */
class WebSocketOverCOMETSession {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var \PHPDaemon\Request\Generic */
	public $downstream;
	/** @var \SplStack */
	public $polling;
	/** @var \PHPDaemon\Structures\StackCallbacks */
	public $callbacks;
	public $authKey;
	public $id;
	public $appInstance;
	/** @var array */
	public $bufferedPackets = [];
	/** @var bool */
	public $finished = false;
	/** @var int */
	public $timeout = 30; // 30
	public $server;

	/**
	 * @param $route
	 * @param $appInstance
	 * @param $authKey
	 */
	public function __construct($route, $appInstance, $authKey) {
		$this->polling     = new \SplStack();
		$this->callbacks   = new \PHPDaemon\Structures\StackCallbacks();
		$this->authKey     = $authKey;
		$this->id          = ++$appInstance->sessCounter;
		$this->appInstance = $appInstance;
		if (!$this->downstream = call_user_func($this->appInstance->WS->routes[$route], $this)) {
			return;
		}
		$this->finishTimer                      = setTimeout([$this, 'finishTimer'], $this->timeout * 1e6);
		$this->appInstance->sessions[$this->id] = $this;
	}

	/**
	 * @TODO DESCR
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
	}

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		if (isset($this->downstream)) {
			$this->downstream->onFinish();
		}
		unset($this->downstream);
		if ($this->finishTimer !== null) {
			\PHPDaemon\Core\Timer::remove($this->finishTimer);
			$this->finishTimer = null;
		}
		unset($this->appInstance->sessions[$this->id]);
	}

	/**
	 * @TODO DESCR
	 * @param $timer
	 */
	public function finishTimer($timer) {
		$this->finish();
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		if ($this->finished) {
			return;
		}
		$this->callbacks->executeAll($this->downstream);
		if (is_callable([$this->downstream, 'onWrite'])) {
			$this->downstream->onWrite();
		}
	}

	/**
	 * @TODO DESCR
	 * @param $a
	 * @param $b
	 * @param int $precision
	 * @return int
	 */
	public function compareFloats($a, $b, $precision = 3) {
		$k   = pow(10, $precision);
		$a   = round($a * $k) / $k;
		$b   = round($b * $k) / $k;
		$cmp = strnatcmp((string)$a, (string)$b);

		return $cmp;
	}

	/**
	 * Flushes buffered packets (only for the long-polling method)
	 * @param string Optional. Last timestamp.
	 * @return void
	 */
	public function flushBufferedPackets($ts = NULL) {
		if ($this->polling->isEmpty() || !sizeof($this->bufferedPackets)) {
			return;
		}

		if ($ts !== NULL) {
			$ts = (float)$ts;

			for ($i = sizeof($this->bufferedPackets) - 1; $i >= 0; --$i) {
				if ($this->compareFloats($this->bufferedPackets[$i][2], $ts) <= 0) {
					$this->bufferedPackets = array_slice($this->bufferedPackets, $i + 1);
					break;
				}
			}
		}

		if (!sizeof($this->bufferedPackets)) {
			return;
		}

		$ts = microtime(true);

		while (!$this->polling->isEmpty()) {
			list ($workerId, $reqId) = $this->polling->pop();
			$workerId = (int)$workerId;
			$this->appInstance->directCall($workerId, 's2c', [$reqId, $this->id, $this->bufferedPackets, $ts]);
		}

		$this->onWrite();
	}

	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = 0x00, $callback = NULL) {
		$this->bufferedPackets[] = [$type, $data, microtime(TRUE)];
		if ($callback !== null) {
			$this->callbacks->push($callback);
		}
		$this->flushBufferedPackets();
		return true;
	}

}