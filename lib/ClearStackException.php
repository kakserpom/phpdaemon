<?php
class ClearStackException extends Exception {
	/**
	 * Thread object
	 * @var object Thread
	 */
	protected $thread;

	/**
	 * Constructor
	 * @param string Message
	 * @param integer Code
	 * @param [object Thread]
	 * @return mixed
	 */
	public function __construct($msg, $code, $thread = null) {
		parent::__construct($msg, $code);
		$this->thread = $thread;
	}

	/**
	 * Gets associated Thread object
	 * @return object Thread
	 */
	public function getThread() {
		return $this->thread;
	}
}
