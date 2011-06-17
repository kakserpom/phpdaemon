<?php

/**
 * Asynchronous process
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AsyncProcess extends AsyncStream {

	/**
	 * Command string
	 * @var string
	 */
	private $cmd;

	/**
	 * Executable path
	 * @var string
	 */
	public $binPath;

	/**
	 * Opened pipes
	 * @var array
	 */
	public $pipes;

	/**
	 * Process descriptor
	 * @var resource Resource? @todo resource?
	 */
	public $pd;

	/**
	 * Output errors? 
	 * @todo used only in this module without any changes
	 * @var boolean
	 */
	public $outputErrors = false;

	// @todo make methods setUser and setGroup, variables change to $user and $group with null values
	public $setUser;                               // optinal SUID.
	public $setGroup;                              // optional SGID.

	// @todo the same, make a method setChroot
	public $chroot = '/';                          // optional chroot.

	public $env = array();                         // hash of environment's variables

	// @todo setCwd
	public $cwd;                                   // optional chdir
	public $errlogfile = '/tmp/cgi-errorlog.log';  // path to error logfile
	public $args;                                  // array of arguments

	// @todo setNice
	public $nice;                                  // optional priority
 
	/**
	 * AsyncProcess constructor
	 * @param string Command's string
	 * @return void
	 */
	public function __construct($cmd = NULL) {
		$this->base = Daemon::$process->eventBase;
		$this->env = $_ENV;
		$this->cmd = $cmd;
	}

	/**
	 * Sets an array of arguments
	 * @param array Arguments
	 * @return object AsyncProccess
	 */
	public function setArgs($args = NULL) {
		$this->args = $args;

		return $this;
	}

	/**
	 * Set a hash of environment's variables
	 * @param array Hash of environment's variables
	 * @return object AsyncProccess
	 */
	public function setEnv($env = NULL) {
		$this->env = $env;

		return $this;
	}

	/**
	 * Set priority.
	 * @param integer Priority
	 * @return object AsyncProccess
	 */
	public function nice($nice = NULL) {
		$this->nice = $nice;

		return $this;
	}

	/**
	 * Execute
	 * @param string Optional. Binpath.
	 * @param array Optional. Arguments.
	 * @param array Optional. Hash of environment's variables.
	 * @return object AsyncProccess
	 */
	public function execute($binPath = NULL, $args = NULL, $env = NULL) {
		if ($binPath !== NULL) {
			$this->binPath = $binPath;
		}

		if ($env !== NULL) {
			$this->env = $env;
		}

		if ($args !== NULL) {
			$this->args = $args;
		}

		$args = '';

		if ($this->args !== NULL) {
			foreach ($this->args as $a) {
				$args .= ' ' . escapeshellcmd($a);
			}
		}

		$this->cmd = $this->binPath . $args . ($this->outputErrors ? ' 2>&1' : '');

		if (
			isset($this->setUser) 
			|| isset($this->setGroup)
		) {
			if (
				isset($this->setUser) 
				&& isset($this->setGroup) 
				&& ($this->setUser !== $this->setGroup)
			) {
				$this->cmd = 'sudo -g ' . escapeshellarg($this->setGroup). '  -u ' . escapeshellarg($this->setUser) . ' ' . $this->cmd;
			} else {
				$this->cmd = 'su ' . escapeshellarg($this->setGroup) . ' -c ' . escapeshellarg($this->cmd);
			}
		}

		if ($this->chroot !== '/') {
			$this->cmd = 'chroot ' . escapeshellarg($this->chroot) . ' ' . $this->cmd;
		}

		if ($this->nice !== NULL) {
			$this->cmd = 'nice -n ' . ((int) $this->nice) . ' ' . $this->cmd;
		}

		$pipesDescr = array(
			0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
			1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
		);

		if (
			($this->errlogfile !== NULL) 
			&& !$this->outputErrors
		) {
			$pipesDescr[2] = array('file', $this->errlogfile, 'a');
		}

		$this->pd = proc_open($this->cmd, $pipesDescr, $this->pipes, $this->cwd, $this->env);

		if ($this->pd) {
			$this->setFD($this->pipes[1], $this->pipes[0]);
			$this->enable();
		}

		return $this;
	}

	/**
	 * Close the process
	 * @return void
	 */
	public function close() {
		$this->closeRead();
		$this->closeWrite();

		if ($this->pd) {
			proc_close($this->pd);
		}
	}

	/**
	 * Tests for end-of-file on a process pointer
	 * @return boolean EOF?
	 */
	public function eof() {
		if (!$this->EOF) {
			$stat = proc_get_status($this->pd);

			if (
				!$stat 
				|| ($stat['running'] == FALSE)
			) {
				$this->onEofEvent();
			}
		}

		return $this->EOF;
	}

}
