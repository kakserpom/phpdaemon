<?php
class ClearStackException extends Exception {
	public $thread;
	public function __construct($msg, $code, $thread) {
		parent::__construct($msg, $code);
		$this->thread = $thread;
	}
}
