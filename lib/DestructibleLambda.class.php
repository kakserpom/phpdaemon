<?php

/**
 * Destructible lambda function class
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class DestructibleLambda {

	/**
	 * Identifier
	 * @var int
	 */
	private $id;

	/**
	 * Lambda function cache hits counter (used in Daemon_WorkerThread)
	 * @var int
	 */
	public $hits = 0;
	
	/**
	 * Constructor
	 * @param int Identifier
	 * @return void
	 */
	public function __construct($id) {
		$this->id = (int) binarySubstr($id, 8);
	}

	/**
	 * Invoking the function
	 * @return void
	 */
	public function __invoke() {
		return call_user_func_array(
			"\x00lambda_" . $this->id, 
			func_get_args()
		);
	}

	/**
	 * Destructor
	 * @return void
	 */
	public function __destruct() {
		runkit_function_remove("\x00lambda_" . $this->id);
	}
}
