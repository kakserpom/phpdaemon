<?php
namespace PHPDaemon\Applications;

class WebSocketOverCOMETSession {
	public $downstream;
	public $polling;
	public $callbacks;
	public $authKey;
	public $id;
	public $appInstance;
	public $bufferedPackets = array();
	public $finished = false;
	public $timeout = 30; // 30
	public $server;

	public function __construct($route, $appInstance, $authKey) {
		$this->polling     = new \SplStack();
		$this->callbacks   = new \PHPDaemon\StackCallbacks();
		$this->authKey     = $authKey;
		$this->id          = ++$appInstance->sessCounter;
		$this->appInstance = $appInstance;
		if (!$this->downstream = call_user_func($this->appInstance->WS->routes[$route], $this)) {
			return;
		}
		$this->finishTimer                      = setTimeout(array($this, 'finishTimer'), $this->timeout * 1e6);
		$this->appInstance->sessions[$this->id] = $this;
	}

	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
	}

	public function onFinish() {
		if (isset($this->downstream)) {
			$this->downstream->onFinish();
		}
		unset($this->downstream);
		if ($this->finishTimer !== null) {
			\PHPDaemon\Timer::remove($this->finishTimer);
			$this->finishTimer = null;
		}
		unset($this->appInstance->sessions[$this->id]);
	}

	public function finishTimer($timer) {
		$this->finish();
	}

	public function onWrite() {
		if ($this->finished) {
			return;
		}
		$this->callbacks->executeAll($this->downstream);
		if (is_callable(array($this->downstream, 'onWrite'))) {
			$this->downstream->onWrite();
		}
	}

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
			$this->appInstance->directCall($workerId, 's2c', array($reqId, $this->id, $this->bufferedPackets, $ts));
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
		$this->bufferedPackets[] = array($type, $data, microtime(TRUE));
		if ($callback !== null) {
			$this->callbacks->push($callback);
		}
		$this->flushBufferedPackets();
		return true;
	}

}