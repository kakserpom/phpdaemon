<?php

/**
 * @package Applications
 * @subpackage CGI
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CGI extends AppInstance {

	public $binPath = 'php-cgi';   // Default bin-path
	public $binAliases = array(
		'php5'   => '/usr/local/php/bin/php-cgi',
		'php6'   => '/usr/local/php6/bin/php-cgi',
		'perl'   => '/usr/bin/perl',
		'python' => '/usr/local/bin/python',
		'ruby'   => '/usr/local/bin/ruby',
	);

	public $chroot = '/';          // default chroot

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'allow-override-binpath' => TRUE,
			'allow-override-cwd' => TRUE,
			'allow-override-chroot' => TRUE,
			'allow-override-user' => TRUE,
			'allow-override-group' => TRUE,
			'cwd' => NULL,
			'output-errors' => TRUE,
			'errlog-file' => __DIR__ . '/cgi-error.log',
		);
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {

	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new CGIRequest($this, $upstream, $req);
	}
}

class CGIRequest extends HTTPRequest {

	public $terminateOnAbort = FALSE;
	public $proc;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->header('Content-Type: text/html'); // default header.

		$this->proc = new AsyncProcess;
		$this->proc->readPacketSize = $this->appInstance->readPacketSize;
		$this->proc->onReadData(array($this,'onReadData'));
		$this->proc->onWrite(array($this,'onWrite'));
		$this->proc->binPath = $this->appInstance->binPath;
		$this->proc->chroot = $this->appInstance->chroot;

		if (isset($this->attrs->server['BINPATH'])) {
			if (isset($this->appInstance->binAliases[$this->attrs->server['BINPATH']])) {
				$this->proc->binPath = $this->appInstance->binAliases[$this->attrs->server['BINPATH']];
			}
			elseif ($this->appInstance->config->allowoverridebinpath->value) {
				$this->proc->binPath = $this->attrs->server['BINPATH'];
			}
		}

		if (
			isset($this->attrs->server['CHROOT']) 
			&& $this->appInstance->config->allowoverridechroot->value
		) {
			$this->proc->chroot = $this->attrs->server['CHROOT'];
		}

		if (
			isset($this->attrs->server['SETUSER']) 
			&& $this->appInstance->config->allowoverrideuser->value
		) {
			$this->proc->setUser = $this->attrs->server['SETUSER'];
		}

		if (
			isset($this->attrs->server['SETGROUP']) 
			&& $this->appInstance->config->allowoverridegroup->value
		) {
			$this->proc->setGroup = $this->attrs->server['SETGROUP'];
		}

		if (
			isset($this->attrs->server['CWD']) 
			&& $this->appInstance->config->allowoverridecwd->value
		) {
			$this->proc->cwd = $this->attrs->server['CWD'];
		}
		elseif ($this->appInstance->config->cwd->value !== NULL) {
			$this->proc->cwd = $this->appInstance->config->cwd->value;
		} else {
			$this->proc->cwd = dirname($this->attrs->server['SCRIPT_FILENAME']);
		}

		$this->proc->setArgs(array($this->attrs->server['SCRIPT_FILENAME']));
		$this->proc->setEnv($this->attrs->server);
		$this->proc->execute();
	}

	/**
	 * Called when request iterated.
	 * @return void
	 */
	public function run() {
		if (!$this->proc) {
			$this->out('Couldn\'t execute CGI proccess.');
			return 1;
		}

		if (!$this->proc->eof()) {
			$this->sleep();
		}

		return 1;
	}

	/**
	 * Called when the request aborted.
	 * @return void
	 */
	public function onAbort() {
		if (
			$this->terminateOnAbort 
			&& $this->stream
		) {
			$this->stream->close();
		}
	}

	/**
	 * Called when the request aborted.
	 * @return void
	 */
	public function onWrite($process) {
		if (
			$this->attrs->stdin_done 
			&& ($this->proc->writeState === FALSE)
		) {
			$this->proc->closeWrite();
		}
	}

	/**
	 * Called when new data received from process.	
	 * @param object Process pointer.
	 * @param string Data.
	 * @return void
	 */
	public function onReadData($process, $data) {
		$this->combinedOut($data);
	}

	/**
	 * Called when new piece of request's body is received.	
	 * @param string Piece of request's body.
	 * @return void
	 */
	public function stdin($c) {
		if ($c === '') {
			return $this->onWrite($this->proc);
		}

		$this->proc->write($c);
	}
	
}
