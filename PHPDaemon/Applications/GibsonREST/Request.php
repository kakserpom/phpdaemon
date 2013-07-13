<?php
namespace PHPDaemon\Applications\GibsonREST;
use PHPDaemon\Core\Daemon;

class Request extends \PHPDaemon\HTTPRequest\Generic {

	protected $result;
	protected $cmd;
	protected $args;

	/*
     * Constructor.
     * @return void
     */
    public function init() {
    	try {
			$this->header('Content-Type: text/plain');
			//$this->header('Content-Type: application/x-json');
		} catch(\Exception $e) {}
		if (isset($this->attrs->server['SUBPATH'])) {
			$e = explode('/', $this->attrs->server['SUBPATH']);
			$this->cmd = array_shift($e);
			$this->args = sizeof($e) ? array_map('urldecode', $e) : null;
		} else {
    		$this->cmd = static::getString($_GET['cmd']);
    	}
    	if (!$this->appInstance->gibson->isCommand($this->cmd)) {
    		$this->result = ['$err' => 'Unrecognized command'];
    		return;
    	}
    	if ($this->args === null) {
    		$this->args = static::getArray($_GET['args']);
    	}
    	$args = $this->args;
    	$args[] = function ($conn) {
    		if (!$conn->isFinal()) {
    			return;
    		}
    		$this->result = $conn->result;
    		$this->wakeup();
    	};
    	call_user_func_array([$this->appInstance->gibson, $this->cmd], $args);
    	$this->sleep(5, true); // setting timeout 5 seconds */
    }

	/**
 	 * Called when request iterated.
 	 * @return integer Status.
 	 */
	public function run() {
		echo json_encode($this->result);
	}
}