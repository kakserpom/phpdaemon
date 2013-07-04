<?php
namespace PHPDaemon\Clients\Mongo;

class Cursor {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	public $id; // Cursor's ID.
	public $col; // Collection's name.
	public $items = []; // Array of objects
	public $item; // Current object
	protected $conn; // Network connection
	public $finished = false; // Is this cursor finished?
	public $failure = false; // Is this query failured?
	public $await = false; // awaitCapable?
	public $destroyed = false; // Is this cursor destroyed?
	public $parseOplog = false;
	public $tailable;
	public $callback;

	public function isBusyConn() {
		if (!$this->conn) {
			return false;
		}
		return $this->conn->isBusy();
	}

	public function getConn() {
		return $this->conn;
	}

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
