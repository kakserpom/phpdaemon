<?php
class ClearStackException extends Exception {
	public $thread;
	public function __construct($msg, $code, $thread = null) {
		parent::__construct($msg, $code);
		$this->thread = $thread;
	}
}
