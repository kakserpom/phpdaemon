<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\CallbackWrapper;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 * @author     Efimenko Dmitriy <ezheg89@gmail.com>
 */
class AutoScan {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $conn;

	protected $cmd;

	protected $cursor = 0;

	protected $args;

	protected $limit = false;

	protected $num = 0;

	protected $isFreeze = false;

	protected $cb;

	protected $cbEnd;

	/**
	 * Constructor
	 * @param  Pool    $pool   Redis pool or connection
	 * @param  string  $cmd    Command
	 * @param  array   $args   Arguments
	 * @param  cllable $cbEnd  Callback
	 * @param  integer $limit  Limit
	 */
	public function __construct($pool, $cmd, $args = [], $cbEnd = null, $limit = false) {
		$this->conn  = $pool;
		$this->cmd   = $cmd;
		$this->args  = empty($args) ? [] : $args;
		$this->limit = $limit;
		if (is_numeric($this->args[0])) {
			array_shift($this->args);
		}
		for ($i = sizeof($this->args) - 1; $i >= 0; --$i) {
			$a = $this->args[$i];
			if ((is_array($a) || is_object($a)) && is_callable($a)) {
				$this->cb   = CallbackWrapper::wrap($a);
				$this->args = array_slice($this->args, 0, $i);
				break;
			}
			elseif ($a !== null) {
				break;
			}
		}
		if ($cbEnd !== null) {
			$this->cbEnd = CallbackWrapper::wrap($cbEnd);
		}
		$this->doIteration();
	}

	public function freeze() {
		$this->isFreeze = true;
	}

	public function run() {
		$this->isFreeze = false;
		$this->doIteration();
	}

	public function reset() {
		$this->num = 0;
		$this->isFreeze = false;
	}

	protected function doIteration() {
		if ($this->isFreeze) {
			return;
		}

		$args = $this->args;
		array_unshift($args, $this->cursor);
		$args[] = function($redis) {
			$this->conn   = $redis;
			$this->cursor = $redis->result[0];
			call_user_func($this->cb, $redis);
			
			if (!is_numeric($redis->result[0]) || !$redis->result[0] || ($this->limit && ++$this->num > $this->limit)) {
				call_user_func($this->cbEnd, $redis, $this);
				return;
			}
			$this->doIteration();
		};
		call_user_func_array([$this->conn, $this->cmd], $args);
	}
}
