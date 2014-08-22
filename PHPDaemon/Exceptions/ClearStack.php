<?php
namespace PHPDaemon\Exceptions;

class ClearStack extends \Exception {
	/**
	 * Thread object
	 * @var object Thread
	 */
	protected $thread;

	/**
	 * Constructor
	 * @param string  Message
	 * @param integer Code
	 * @param [object Thread]
	 * @param string $msg
	 * @param integer $code
	 * @param \PHPDaemon\Thread\Generic $thread
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
