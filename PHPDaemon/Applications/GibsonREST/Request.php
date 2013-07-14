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
		if (!$this->importCmdArgs()) {
			return;
		}
		$this->onSessionStart(function() {
			if ($this->cmd === 'LOGIN') {
				if (sizeof($this->args) !== 2) {
					$this->result = ['$err' => 'You must pass exactly 2 arguments.'];
					$this->wakeup();
					return;
				}
				$c1 = \PHPDaemon\Utils\Crypt::compareStrings($this->appInstance->config->username->value, $this->args[0]) ? 0 : 1;
				$c2 = \PHPDaemon\Utils\Crypt::compareStrings($this->appInstance->config->password->value, $this->args[1]) ? 0 : 1;
				if ($c1 + $c2 > 0) {
				 	$this->result = ['$err' => 'Wrong username and/or password.'];
				 	$this->wakeup();
				 	return;
				 }
				 $this->attrs->session['logged'] = $this->appInstance->config->credver;
				 $this->result = ['$ok' => 1];
				 $this->wakeup();
				 return;
			}
			if (!isset($this->attrs->session['logged']) || $this->attrs->session['logged'] < $this->appInstance->config->credver) {
				$this->result = ['$err' => 'You must be authenticated.'];
				$this->wakeup();
				return;
			}
			$this->performCommand();
		});   
		$this->sleep(5, true); // setting timeout 5 seconds */
	}

	/*
	 * Import command name and arguments from input
	 * @return void
	 */
	protected function importCmdArgs() {
		if (isset($this->attrs->server['SUBPATH'])) {
			$e = explode('/', $this->attrs->server['SUBPATH']);
			$this->cmd = array_shift($e);
			$this->args = sizeof($e) ? array_map('urldecode', $e) : null;
		} else {
			$this->cmd = static::getString($_GET['cmd']);
		}
		if (!$this->appInstance->gibson->isCommand($this->cmd) && !in_array($this->cmd, ['LOGIN'])) {
			$this->result = ['$err' => 'Unrecognized command'];
			return false;
		}
		if ($this->args === null) {
			$this->args = static::getArray($_GET['args']);
		}
		return true;
	}

	/*
	 * Performs command
	 * @return void
	 */
	protected function performCommand() {
		$args = $this->args;
		$args[] = function ($conn) {
			if (!$conn->isFinal()) {
				return;
			}
			$this->result = $conn->result;
			$this->wakeup();
		};
		call_user_func_array([$this->appInstance->gibson, $this->cmd], $args);
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		echo json_encode($this->result);
	}
}