<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\CallbackWrapper;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * Current result
	 * @var array|null
	 */
	public $result = null;

	/**
	 * Current error message
	 * @var string
	 */
	public $error;

	/**
	 * Current incoming key
	 * @var string
	 */
	protected $key;

	/**
	 * Current result length
	 * @var integer
	 */
	protected $resultLength = 0;

	/**
	 * Current value length
	 * @var integer
	 */
	protected $valueLength = 0;

	/**
	 * EOL
	 * @var string "\r\n"
	 */
	protected $EOL = "\r\n";

	protected $subscribed = false;

	/**
	 * Subcriptions
	 * @var array
	 */
	public $subscribeCb = [];
	public $psubscribeCb = [];

	/**
	 * In the middle of binary response part
	 * @const integer
	 */
	const STATE_BINARY = 1;

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		parent::onReady();
		$this->setWatermark(null, $this->pool->maxAllowedPacket);
	}


	public function subscribed() {
		if ($this->subscribed) {
			return;
		}
		$this->subscribed = true;
		$this->pool->servConnSub[$this->url] = $this;
		$this->checkFree();
	}

	public function isSubscribed() {
		return $this->subscribed;
	}

	public function command($name, $args, $cb = null) {
		// PUB/SUB handling
		$cb = CallbackWrapper::wrap($cb);
		if ($name === 'SUBSCRIBE') {
			foreach ($args as $arg) {
				if (!isset($this->subscribeCb[$arg])) {
					$this->sendCommand($name, $arg);
				}
				CallbackWrapper::addToArray($this->subscribeCb[$arg], $cb);
			}
			$this->subscribed();
		}
		elseif ($name === 'PSUBSCRIBE') {
			foreach ($args as $arg) {
				if (!isset($this->psubscribeCb[$arg])) {
					$this->sendCommand($name, $arg);
				}
				CallbackWrapper::addToArray($this->psubscribeCb[$arg], $cb);
			}
			$this->subscribed();
		}
		elseif ($name === 'UNSUBSCRIBE') {
			foreach ($args as $arg) {
				CallbackWrapper::removeFromArray($this->subscribeCb[$arg], $cb);
				if (sizeof($this->subscribeCb[$arg]) === 0) {
					$this->sendCommand($name, $arg);
					unset($this->subscribeCb[$arg]);
				}
			}
		}
		elseif ($name === 'PUNSUBSCRIBE') {
			foreach ($args as $arg) {
				CallbackWrapper::removeFromArray($this->psubscribeCb[$arg], $cb);
				if (sizeof($this->psubscribeCb[$arg]) === 0) {
					$this->sendCommand($name, $arg);
					unset($this->psubscribeCb[$arg]);
				}
			}
		} else {
			$this->sendCommand($name, $args, $cb);
		}
 	}

 	public function sendCommand($name, $args, $cb = null) {
 		$this->onResponse($cb);
 		if (!is_array($args)) {
			$args = [$args];
		}
 		array_unshift($args, $name);
		$s = sizeof($args);
		$this->writeln('*' . $s);
		foreach ($args as $arg) {
			$this->writeln('$' . strlen($arg));
			$this->writeln($arg);
		}
 	}

	/**
	 * Check if arrived data is message from subscription
	 */
	protected function isSubMessage() {
		if (sizeof($this->result) < 3) {
			return false;
		}
		if (!$this->subscribed) {
			return false;
		}
		$mtype = strtolower($this->result[0]);
		if ($mtype !== 'message' && $mtype !== 'pmessage') {
			return false;
		}
		return $mtype;
	}
	
	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		/* we should reassign subscriptions */
		foreach ($this->subscribeCb as $sub => $cbs) {
			foreach ($cbs as $cb) {
				call_user_func([$this->pool, 'subscribe'], $sub, $cb);
			}
		}
		foreach ($this->psubscribeCb as $sub => $cbs) {
			foreach ($cbs as $cb) {
				call_user_func([$this->pool, 'psubscribe'], $sub, $cb);
			}
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	protected function onRead() {
		start:
		Daemon::log(json_encode([$this->state, $this->lowMark, $this->highMark, $this->look(1024)]));
		if (($this->result !== null) && (sizeof($this->result) >= $this->resultLength)) {
			if (($mtype = $this->isSubMessage()) !== false) { // sub callback
				$chan = $this->result[1];
				if ($mtype === 'pmessage') {
					$t = $this->psubscribeCb;
				} else {
					$t = $this->subscribeCb;
				}
				if (isset($t[$chan])) {
					foreach ($t[$chan] as $cb) {
						if (is_callable($cb)) {
							call_user_func($cb, $this);
						}
					}
				}
			}
			else { // request callback
				$this->onResponse->executeOne($this);
			}
			if (!$this->subscribed) {
				$this->checkFree();
			}
			$this->resultLength = 0;
			$this->result       = null;
			$this->error        = false;
		}

		if ($this->state === self::STATE_ROOT) { // outside of packet
			while (($l = $this->readline()) !== null) {
				if ($l === '') {
					continue;
				}
				$char = $l{0};
				if ($char === ':') { // inline integer
					if ($this->result !== null) {
						$this->result[] = (int)binarySubstr($l, 1);
					}
					else {
						$this->resultLength = 1;
						$this->result       = [(int)binarySubstr($l, 1)];
					}
					goto start;
				}
				elseif (($char === '+') || ($char === '-')) { // inline string
					$this->resultLength = 1;
					$this->error        = ($char === '-');
					$this->result       = [binarySubstr($l, 1)];
					goto start;
				}
				elseif ($char === '*') { // defines number of elements of incoming array
					$this->resultLength = (int)substr($l, 1);
					$this->result       = [];
					goto start;
				}
				elseif ($char === '$') { // defines size of the data block
					$this->valueLength = (int)substr($l, 1);
					$this->setWatermark($this->valueLength + 2);
					$this->state = self::STATE_BINARY; // binary data block
					break; // stop reading line-by-line
				}
			}
		}

		if ($this->state === self::STATE_BINARY) { // inside of binary data block
			if ($this->getInputLength() < $this->valueLength + 2) {
				return; //we do not have a whole packet
			}
			$value = $this->read($this->valueLength);
			if ($this->read(2) !== $this->EOL) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(1);
			$this->result[] = $value;
			goto start;
		}
	}
}
