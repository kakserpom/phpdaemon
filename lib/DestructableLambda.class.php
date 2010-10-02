<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class DestructableLambda
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description DestructableLambda class.
/**************************************************************************/

class DestructableLambda {
	public $id;
	public $hits = 0;
	
	public function __construct($id) {
		$this->id = (int) binarySubstr($id,8);
	}

	public function __invoke() {
		return call_user_func_array(
			"\x00lambda_" . $this->id, 
			func_get_args()
		);
	}

	public function __destruct() {
		runkit_function_remove("\x00lambda_" . $this->id);
	}
}
