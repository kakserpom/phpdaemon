<?php
namespace PHPDaemon\Clients\Mongo;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    Applications
 * @subpackage MongoClientAsync
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Cursor implements \Iterator {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var mixed Cursor's ID
	 */
	public $id;

	/**
	 * @var string Collection's name
	 */
	public $col;

	/**
	 * @var array Array of objects
	 */
	public $items = [];

	/**
	 * @var mixed Current object
	 */
	public $item;

	/**
	 * @var mixed Network connection
	 */
	protected $conn;

	/**
	 * @var boolean Is this cursor finished?
	 */
	public $finished = false;

	/**
	 * @var boolean Is this query failured?
	 */
	public $failure = false;

	/**
	 * @var boolean awaitCapable?
	 */
	public $await = false;

	/**
	 * @var boolean Is this cursor destroyed?
	 */
	public $destroyed = false;

	/**
	 * @var boolean
	 */
	public $parseOplog = false;

	/**
	 * @var boolean
	 */
	public $tailable;

	/**
	 * @var callable
	 */
	public $callback;

	public $counter = 0;

	protected $pos = 0;

	protected $keep = false;

	/**
	 * Error
	 * @return mixed
	 */
	public function error() {
		return isset($this->items['$err']) ? $this->items['$err'] : false;
	}

	/**
	 * Keep
	 * @param  boolean $bool
	 * @return void
	 */
	public function keep($bool = true) {
		$this->keep = (bool) $bool;
	}

	/**
	 * Rewind
	 * @return void
	 */
	public function rewind() {
		reset($this->items);
	}

	/**
	 * Current
	 * @return mixed
	 */
	public function current() {
		return isset($this->items[$this->pos]) ? $this->items[$this->pos] : null;
	}

	/**
	 * Key
	 * @return string
	 */
	public function key() {
		return $this->pos;
	}

	/**
	 * Next
	 * @return void
	 */
	public function next() {
		if ($this->keep) {
			++$this->pos;
		} else {
			array_shift($this->items);
		}
	}

	/**
	 * Grab
	 * @return array
	 */
	public function grab() {
		$items = $this->items;
		$this->items = [];
		return $items;
	}

	/**
	 * To array
	 * @return array
	 */
	public function toArray() {
		$items = $this->items;
		$this->items = [];
		return $items;
	}

	/**
	 * Valid
	 * @return boolean
	 */
	public function valid() {
		$key = isset($this->items[$this->pos]) ? $this->items[$this->pos] :null;
		return ($key !== NULL && $key !== FALSE);
	}

	/**
	 * @TODO DESCR
	 * @return boolean
	 */
	public function isBusyConn() {
		if (!$this->conn) {
			return false;
		}
		return $this->conn->isBusy();
	}

	/**
	 * @TODO DESCR
	 * @return Connection
	 */
	public function getConn() {
		return $this->conn;
	}

	/**
	 * @TODO DESCR
	 * @return boolean
	 */
	public function isFinished() {
		return $this->finished;
	}

	/**
	 * Constructor
	 * @param  string     $id   Cursor's ID
	 * @param  string     $col  Collection's name
	 * @param  Connection $conn Network connection (MongoClientConnection)
	 * @return void
	 */
	public function __construct($id, $col, $conn) {
		$this->id   = $id;
		$this->col  = $col;
		$this->conn = $conn;
	}

	/**
	 * Asks for more objects
	 * @param  integer $number Number of objects
	 * @return void
	 */
	public function getMore($number = 0) {
		if ($this->finished || $this->destroyed) {
			return;
		}
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->conn->pool->getMore($this->col, binarySubstr($this->id, 1), $number, $this->conn);
		}
	}

	/**
	 * isDead
	 * @return boolean
	 */
	public function isDead() {
		return $this->finished || $this->destroyed;
	}

	/**
	 * Destroys the cursors
	 * @param  boolean $notify
	 * @return boolean Success
	 */
	public function destroy($notify = false) {
		if ($this->destroyed) {
			return false;
		}
		$this->destroyed = true;
		if ($notify) {
			if ($this->callback) {
				call_user_func($this->callback, $this);
			}
		}
		unset($this->conn->cursors[$this->id]);
		return true;
	}

	/**
	 * Destroys the cursors
	 * @param  boolean $notify
	 * @return boolean Success
	 */
	public function free($notify = false) {
		return $this->destroy($notify);
	}

	/**
	 * Cursor's destructor. Sends a signal to the server
	 * @return void
	 */
	public function __destruct() {
		try {
			if (binarySubstr($this->id, 0, 1) === 'c') {
				$this->conn->pool->killCursors([binarySubstr($this->id, 1)], $this->conn);
			}
		} catch (ConnectionFinished $e) {}
	}
}
