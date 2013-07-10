<?php
namespace PHPDaemon\Clients\Mongo;

class Cursor {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var mixed Cursor's ID */
	public $id;
	/** @var Collection's name */
	public $col;
	/** @var array Array of objects */
	public $items = [];
	/** @var mixed Current object */
	public $item;
	/** @var mixed Network connection */
	protected $conn;
	/** @var bool Is this cursor finished? */
	public $finished = false;
	/** @var bool Is this query failured? */
	public $failure = false;
	/** @var bool awaitCapable? */
	public $await = false;
	/** @var bool Is this cursor destroyed? */
	public $destroyed = false;
	/** @var bool */
	public $parseOplog = false;
	/** @var */
	public $tailable;
	/** @var */
	public $callback;

	/**
	 * @TODO DESCR
	 * @return bool
	 */
	public function isBusyConn() {
		if (!$this->conn) {
			return false;
		}
		return $this->conn->isBusy();
	}

	/**
	 * @TODO DESCR
	 * @return mixed
	 */
	public function getConn() {
		return $this->conn;
	}

	/**
	 * @TODO DESCR
	 * @return bool
	 */
	public function isFinished() {
		return $this->finished;
	}

	/**
	 * Constructor
	 * @param string Cursor's ID
	 * @param string Collection's name
	 * @param object Network connection (MongoClientConnection),
	 * @return void
	 */
	public function __construct($id, $col, $conn) {
		$this->id   = $id;
		$this->col  = $col;
		$this->conn = $conn;
	}

	/**
	 * Asks for more objects
	 * @param integer Number of objects
	 * @return void
	 */
	public function getMore($number = 0) {
		/*if ($this->tailable && $this->await) {
			return;
		}*/
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->conn->pool->getMore($this->col, binarySubstr($this->id, 1), $number, $this->conn);
		}
	}

	/**
	 * Destroys the cursors
	 * @return boolean Success
	 */
	public function destroy() {
		$this->destroyed = true;
		unset($this->conn->cursors[$this->id]);
		return true;
	}

	/**
	 * Cursor's destructor. Sends a signal to the server.
	 * @return void
	 */
	public function __destruct() {
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->conn->pool->killCursors([binarySubstr($this->id, 1)]);
		}
	}
}
