<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\CallbackWrapper;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Connection extends ClientConnection implements \Iterator {
	/**
	 * @var array|null Current result
	 */
	public $result = null;

	/**
	 * @var string Current error message
	 */
	public $error;

	/**
	 * @var string Current incoming key
	 */
	protected $key;


	protected $stack = [];
	
	protected $ptr;

	/**
	 * @var integer Current value length
	 */
	protected $valueLength = 0;


	/**
	 * @var integer Current level length
	 */
	protected $levelLength = null;

	/**
	 * @var string
	 */
	protected $EOL = "\r\n";

	/**
	 * @var boolean Is it a subscription connection?
	 */
	protected $subscribed = false;

	/**
	 * @var float Timeout
	 */
	protected $timeoutRead = 5;

	/**
	 * @var array Subcriptions
	 */
	public $subscribeCb = [];

	public $psubscribeCb = [];

	protected $maxQueue = 10;

	protected $pos = 0;

	protected $assocData = null;

	/**
	 * In the middle of binary response part
	 */
	const STATE_BINARY = 1;

	public function rewind() {
		$this->pos = 0;
	}

	public function current() {
		if (!is_array($this->result)) {
			return $this->pos === 0 ? $this->result : null;
		}
		return isset($this->result[$this->pos * 2 + 1]) ? $this->result[$this->pos * 2 + 1] : $this->result;
	}

	public function key() {
		if (!is_array($this->result)) {
			return $this->pos === 0 ? 0: null;
		}
		return $this->result[$this->pos * 2] ? $this->result[$this->pos * 2] : false;
	}

	public function next() {
		++$this->pos;
		return $this->current();
	}

	public function valid() {
		return is_array($this->result) ? isset($this->result[$this->pos * 2 + 1]) : false;
	}

	public function __get($name) {
		if ($name === 'assoc') {
			if ($this->assocData === null) {
				if(!is_array($this->result) || empty($this->result)) {
					$this->assocData = [];
				} else {
					$hash = [];
					for ($i = 0, $s = sizeof($this->result) - 1; $i < $s; ++$i) {
						$hash[$this->result[$i]] = $this->result[++$i];
					}
					$this->assocData = $hash;
				}
			}
			return $this->assocData;
		}
	}

	/**
	 * @TODO
	 * @param  string  $key
	 * @param  integer $timeout
	 * @return Lock
	 */
	public function lock($key, $timeout) {
		return new Lock($key, $timeout, $this);
	}

	/**
	 * Easy wrapper for queue of eval's
	 * @param  callable  $cb
	 * @return MultiEval
	 */
	public function meval($cb = null) {
		return new MultiEval($cb, $this);
	}

	/**
	 * @TODO
	 * @param  string $chan
	 * @return integer
	 */
	public function getLocalSubscribersCount($chan) {
		if (!isset($this->subscribeCb[$chan])) {
			return 0;
		}
		return sizeof($this->subscribeCb[$chan]);
	}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->ptr =& $this->result;
		if (!isset($this->password)) {
			if (isset($this->pool->config->select->value)) {
				$this->select($this->pool->config->select->value);
			}
			parent::onReady();
			$this->setWatermark(null, $this->pool->maxAllowedPacket + 2);
			return;
		}
		$this->sendCommand('AUTH', [$this->password], function () {
			if ($this->result !== 'OK') {
				$this->log('Auth. error: ' . json_encode($this->result));
				$this->finish();
			}
			if (isset($this->pool->config->select->value)) {
				$this->select($this->pool->config->select->value);
			}
			parent::onReady();
			$this->setWatermark(null, $this->pool->maxAllowedPacket + 2);
		});
	}

	/**
	 * Magic __call
	 * Example:
	 * $redis->lpush('mylist', microtime(true));
	 * @param  sting $cmd
	 * @param  array $args
	 * @return void
	 */
	public function __call($cmd, $args) {
		$cb = null;
		for ($i = sizeof($args) - 1; $i >= 0; --$i) {
			$a = $args[$i];
			if ((is_array($a) || is_object($a)) && is_callable($a)) {
				$cb = CallbackWrapper::wrap($a);
				$args = array_slice($args, 0, $i);
				break;
			}
			elseif ($a !== null) {
				break;
			}
		}
		$cmd = strtoupper($cmd);
		$this->command($cmd, $args, $cb);
	}

	/**
	 * @TODO
	 * @param  string   $name
	 * @param  array    $args
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return void
	 */
	public function command($name, $args, $cb = null) {
		if ($name === 'MULTI') {
			$this->acquire();
		}
		// PUB/SUB handling
		elseif (substr($name, -9) === 'SUBSCRIBE') {
			if (!$this->subscribed) {
				$this->subscribed = true;
				$this->pool->servConnSub[$this->url] = $this;
				$this->acquire();
				$this->setTimeouts(86400, 86400); // @TODO: remove timeout
			}

			$opcb = null;
			for ($i = sizeof($args) - 1; $i >= 0; --$i) {
				$a = $args[$i];
				if ((is_array($a) || is_object($a)) && is_callable($a)) {
					$opcb = $cb;
					$cb = CallbackWrapper::wrap($a);
					$args = array_slice($args, 0, $i);
					break;
				}
				elseif ($a !== null) {
					break;
				}
			}
		}
		if ($name === 'SUBSCRIBE') {
			$this->subscribed();
			$channels = [];
			foreach ($args as $arg) {
				if (!is_array($arg)) {
					$arg = [$arg];
				}
				foreach ($arg as $chan) {
					$b = !isset($this->subscribeCb[$chan]);
					CallbackWrapper::addToArray($this->subscribeCb[$chan], $cb);
					if ($b) {
						$channels[] = $chan;
					} else {
						if ($opcb !== null) {
							call_user_func($opcb, $this);
						}
					}
				}
			}
			if (sizeof($channels)) {
				$this->sendCommand($name, $channels, $opcb);
			}
		}
		elseif ($name === 'PSUBSCRIBE') {
			$this->subscribed();
			$channels = [];
			foreach ($args as $arg) {
				if (!is_array($arg)) {
					$arg = [$arg];
				}
				foreach ($arg as $chan) {
					$b = !isset($this->psubscribeCb[$chan]);
					CallbackWrapper::addToArray($this->psubscribeCb[$chan], $cb);
					if ($b) {
						$channels[] = $chan;
					} else {
						if ($opcb !== null) {
							call_user_func($opcb, $this);
						}
					}
				}
			}
			if (sizeof($channels)) {
				$this->sendCommand($name, $channels, $opcb);
			}
		}
		elseif ($name === 'UNSUBSCRIBE') {
			$channels = [];
			foreach ($args as $arg) {
				if (!is_array($arg)) {
					$arg = [$arg];
				}
				foreach ($arg as $chan) {
					if (!isset($this->subscribeCb[$chan])) {
						if ($opcb !== null) {
							call_user_func($opcb, $this);
						}
						return;
					}
					CallbackWrapper::removeFromArray($this->subscribeCb[$chan], $cb);
					if (sizeof($this->subscribeCb[$chan]) === 0) {
						$channels[] = $chan;
						unset($this->subscribeCb[$chan]);
					} else {
						if ($opcb !== null) {
							call_user_func($opcb, $this);
						}
					}
				}
			}
			if (sizeof($channels)) {
				$this->sendCommand($name, $channels, $opcb);
			}
		}
		elseif ($name === 'UNSUBSCRIBEREAL') {
			
			/* Race-condition-free UNSUBSCRIBE */

			$old = $this->subscribeCb;
			$this->sendCommand('UNSUBSCRIBE', $args, function($redis) use ($cb, $args, $old) {
				if (!$redis) {
					call_user_func($cb, $redis);
					return;
				}
				foreach ($args as $arg) {
					if (!isset($this->subscribeCb[$arg])) {
						continue;
					}
					foreach ($old[$arg] as $oldcb) {
						CallbackWrapper::removeFromArray($this->subscribeCb[$arg], $oldcb);
					}
					if (!sizeof($this->subscribeCb[$arg])) {
						unset($this->subscribeCb[$arg]);
					}
				}
				if ($cb !== null) {
					call_user_func($cb, $this);
				}
			});
		}
		elseif ($name === 'PUNSUBSCRIBE') {
			$channels = [];
			foreach ($args as $arg) {
				if (!is_array($arg)) {
					$arg = [$arg];
				}
				foreach ($arg as $chan) {
					CallbackWrapper::removeFromArray($this->psubscribeCb[$chan], $cb);
					if (sizeof($this->psubscribeCb[$chan]) === 0) {
						$channels[] = $chan;
						unset($this->psubscribeCb[$chan]);
					} else {
						if ($opcb !== null) {
							call_user_func($opcb, $this);
						}
					}
				}
			}
			if (sizeof($channels)) {
				$this->sendCommand($name, $channels, $opcb);
			}
		}
		elseif ($name === 'PUNSUBSCRIBEREAL') {
			
			/* Race-condition-free PUNSUBSCRIBE */

			$old = $this->psubscribeCb;
			$this->sendCommand('PUNSUBSCRIBE', $args, function($redis) use ($cb, $args, $old) {
				if (!$redis) {
					call_user_func($cb, $redis);
					return;
				}
				foreach ($args as $arg) {
					if (!isset($this->psubscribeCb[$arg])) {
						continue;
					}
					foreach ($old[$arg] as $oldcb) {
						CallbackWrapper::removeFromArray($this->psubscribeCb[$arg], $oldcb);
					}
					if (!sizeof($this->psubscribeCb[$arg])) {
						unset($this->psubscribeCb[$arg]);
					}
				}
				if ($cb !== null) {
					call_user_func($cb, $this);
				}
			});
		} else {
			$this->sendCommand($name, $args, $cb);

			if ($name === 'EXEC' || $name === 'DISCARD') {
				$this->release();
			}
		}
	}

	/**
	 * @TODO
	 * @param  string   $name
	 * @param  array    $args
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return void
	 */
	public function sendCommand($name, $args, $cb = null) {
		$this->onResponse($cb);
		if (!is_array($args)) {
			$args = [$args];
		}
		array_unshift($args, $name);
		$this->writeln('*' . sizeof($args));
		foreach ($args as $arg) {
			$this->writeln('$' . strlen($arg) . $this->EOL . $arg);
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
		if ($this->subscribed) {
			unset($this->pool->servConnSub[$this->url]);
		}
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

	protected function onPacket() {
		$this->result = $this->ptr;
		if (!$this->subscribed) {
			$this->onResponse->executeOne($this);
			goto clean;
		} elseif ($this->result[0] === 'message') {
			$t = &$this->subscribeCb;
		} elseif ($this->result[0] === 'pmessage') {
			$t = &$this->psubscribeCb;
		} else {
			$this->onResponse->executeOne($this);
			goto clean;
		}
		if (isset($t[$this->result[1]])) {
			foreach ($t[$this->result[1]] as $cb) {
				if (is_callable($cb)) {
					call_user_func($cb, $this);
				}
			}
		} elseif ($this->pool->config->logpubsubracecondition->value) {
			Daemon::log('[Redis client]'. ': PUB/SUB race condition at channel '. Debug::json($this->result[1]));
		}
		clean:
		$this->result    = null;
		$this->error     = false;
		$this->pos       = 0;
		$this->assocData = null;
		if (!isset($t)) {
			$this->checkFree();
		}
	}

	/**
	 * @TODO
	 * @param  mixed $val
	 * @return void
	 */
	public function pushValue($val) {
		if (is_array($this->ptr)) {
			$this->ptr[] = $val;
		} else {
			$this->ptr = $val;
		}
		start:
		if (sizeof($this->ptr) < $this->levelLength) {
			return;
		}
		array_pop($this->stack);
		if (!sizeof($this->stack)) {
			$this->levelLength = null;
			$this->onPacket();
			$this->ptr =& $dummy;
			$this->ptr = null;
			return;
		}

		$this->ptr =& $dummy;

		list ($this->ptr, $this->levelLength) = end($this->stack);

		goto start;
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	protected function onRead() {
		start:
		if ($this->state === self::STATE_STANDBY) { // outside of packet
			while (($l = $this->readline()) !== null) {
				if ($l === '') {
					continue;
				}
				$char = $l{0};
				if ($char === ':') { // inline integer
					$this->pushValue((int) substr($l, 1));
					goto start;
				}
				elseif (($char === '+') || ($char === '-')) { // inline string
					$this->error = $char === '-';
					$this->pushValue(substr($l, 1));
					goto start;
				}
				elseif ($char === '*') { // defines number of elements of incoming array
					$length = (int) substr($l, 1);
					if ($length <= 0) {
						$this->pushValue([]);
						goto start;
					}

					$ptr = [];
					
					if (is_array($this->ptr)) {
						$this->ptr[] =& $ptr;
					} else {
						$this->ptr =& $ptr;
					}

					$this->ptr =& $ptr;
					$this->stack[] = [&$ptr, $length];
					$this->levelLength = $length;
					$this->ptr =& $ptr;

					goto start;
				}
				elseif ($char === '$') { // defines size of the data block
					if ($l{1} === '-') {
						$this->pushValue(null);
						goto start;
					}
					$this->valueLength = (int)substr($l, 1);
					if ($this->valueLength + 2 > $this->pool->maxAllowedPacket) {
						$this->log('max-allowed-packet ('.$this->pool->config->maxallowedpacket->getHumanValue().') exceed, aborting connection');
						$this->finish();
						return;
					}
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
			$this->state = self::STATE_STANDBY;
			$this->setWatermark(3);
			$this->pushValue($value);
			goto start;
		}
	}
}
