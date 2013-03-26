<?php

/**
 * Thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class Thread {
	
	/**
	 * Process identificator
	 * @var int
	 */
	protected $id;

	/**
	 * PID
	 * @var int
	 */
	protected $pid;

	/**
	 * Is this thread shutdown?
	 * @var boolean
	 */
	protected $shutdown = false;

	/**
	 * Is this thread terminated?
	 * @var boolean
	 */
	protected $terminated = false;

	/**
	 * Collections of childrens
	 * @var array
	 */
	protected $collections = [];

	/**
	 * Array of known signal numbers
	 * @var array
	 */
	protected static $signalsno = [
		1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 
		18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31
	];
	
	/**
	 * Storage of signal handler events
	 * @var array
	 */
	protected $sigEvents = [];
	
	/**
	 * Hash of known signal [no => name, ...]
	 * @var array
	 */
	public static $signals = [
		SIGHUP    => 'SIGHUP',
		SIGSYS    => 'SIGSYS',
		SIGPIPE   => 'SIGPIPE',
		SIGALRM   => 'SIGALRM',
		SIGTERM   => 'SIGTERM',
		SIGSTOP   => 'SIGSTOP',
		SIGCHLD   => 'SIGCHLD',
		SIGTTIN   => 'SIGTTIN',
		SIGTTOU   => 'SIGTTOU',
		SIGIO     => 'SIGIO',
		SIGXCPU   => 'SIGXCPU',
		SIGXFSZ   => 'SIGXFSZ',
		SIGVTALRM => 'SIGVTALRM',
		SIGPROF   => 'SIGPROF',
		SIGWINCH  => 'SIGWINCH',
		SIGUSR1   => 'SIGUSR1',
		SIGUSR2   => 'SIGUSR2',
	];
	
	/**
	 * Get PID of this Thread
	 * @return integer
	 */
	public function getPid() {
		return $this->pid;
	}


	/**
	 * Set ID of this Thread
	 * @param integer Id
	 * @return void
	 */
	public function setId($id) {
		$this->id = $id;
	}

	/**
	 * Get ID of this Thread
	 * @return integer
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Is this thread terminated?
	 * @return boolean
	 */
	public function isTerminated() {
		return $this->terminated;
	}

	/**
	 * Invoke magic method
	 * @return void
	 */

	public function __invoke() {
		$this->run();
		$this->shutdown();
	}
	/**
	 * Register signals.
	 * @return void
	 */
	protected function registerEventSignals() {
		if (!$this->eventBase) {
			return;
		}
		foreach (self::$signals as $no => $name) {
			if (
				($name === 'SIGKILL') 
				|| ($name == 'SIGSTOP')
			) {
				continue;
			}
			$ev = Event::signal($this->eventBase, $no, array($this,'eventSighandler'), array($no));
			if (!$ev) {
				$this->log('Cannot event_set for '.$name.' signal');
			}
			$ev->add();
			$this->sigEvents[$no] = $ev;
		}
	}

	/**
	 * Unregister signals.
	 * @return void
	 */
	protected function unregisterSignals() {
		foreach ($this->sigEvents as $no => $ev) {
			$ev->free();
			unset($this->sigEvents[$no]);
		}
	}

	/**
	 * Called when a signal caught through libevent.
	 * @param integer Signal's number.
	 * @param integer Events.
	 * @param mixed Argument.
	 * @return void
	 */
	public function eventSighandler($fd, $arg) {
		$this->sighandler($arg[0]);
	}

	/**
	 * Run thread process
	 * @return void
	 */
	protected function run() { }

	/**
	 * If true, we do not register signals automatically at start
	 * @var boolean
	 */
	protected $delayedSigReg = false;

	/**
	 * Registers signals
	 * @return void
	 */
	protected function registerSignals()
	{
		foreach (self::$signals as $no => $name) {
			if (
				($name === 'SIGKILL') 
				|| ($name == 'SIGSTOP')
			) {
				continue;
			}

			if (!pcntl_signal($no, array($this, 'sighandler'), TRUE)) {
				$this->log('Cannot assign ' . $name . ' signal');
			}
		}
	}

	/**
	 * Starts the process
	 * @return void
	 */
	public function start($clearstack = true) {
		$pid = pcntl_fork();

		if ($pid === -1) {
			throw new Exception('Could not fork');
		} 
		elseif ($pid === 0) { // we are the child
			$thread = $this;
			$thread->pid = posix_getpid();
			if (!$thread->delayedSigReg) {
				$thread->registerSignals();
			}
			if ($clearstack) {
				throw new ClearStackException('', 0, $thread);
			} else {
				$thread->run();
				$thread->shutdown();
			}
		} else { // we are the master
			$this->pid = $pid;
		}
	}

	/**
	 * Called when the signal is caught
	 * @param integer Signal's number
	 * @return void
	 */
	protected function sighandler($signo) {
		if (is_callable($c = array($this, strtolower(self::$signals[$signo])))) {
			call_user_func($c);
		}
		elseif (is_callable($c = array($this, 'sigunknown'))) {
			call_user_func($c, $signo);
		}
	}

	/** 
	 * Shutdowns the current process properly
	 * @return void
	 */
	protected function shutdown() {
		posix_kill(posix_getppid(), SIGCHLD);
		exit(0);
	}

	/**
	 * Sends the signal to parent process
	 * @param integer Signal's number
	 * @return boolean Success
	 */
	protected function backsig($sig) {
		return posix_kill(posix_getppid(), $sig);
	}

	/**
	 * Delays the process execution for the given number of seconds
	 * @param integer Sleep time in seconds
	 * @return boolean Success
	 */
	public function sleep($s) {
		static $interval = 0.2;
		$n = $s / $interval;

		for ($i = 0; $i < $n; ++$i) {
			if ($this->shutdown) {
				return false;
			}

			usleep($interval * 1000000);
		}

		return true;
	}

	/**
	 * Called when the signal SIGCHLD caught
	 * @return void
	 */
	protected function sigchld() {
		$this->waitPid();
	}

	/**
	 * Called when the signal SIGTERM caught
	 * @return void
	 */
	protected function sigterm() {
		exit(0);
	}

	/**
	 * Called when the signal SIGINT caught
	 * @return void
	 */
	protected function sigint() {
		exit(0);
	}

	/**
	 * Called when the signal SIGTERM caught
	 * @return void
	 */
	protected function sigquit() {
		$this->shutdown = TRUE;
	}

	/**
	 * Called when the signal SIGKILL caught
	 * @return void
	 */
	protected function sigkill() {
		exit(0);
	}

	/**
	 * Terminates the process
	 * @param boolean Kill?
	 * @return void
	 */
	public function stop($kill = false) {
		$this->shutdown = true;
		posix_kill($this->pid, $kill ? SIGKILL : SIGTERM);
	}

	/**
	 * Checks for SIGCHLD
	 * @return boolean Success
	 */
	protected function waitPid() {
		start:
		$pid = pcntl_waitpid(-1, $status, WNOHANG);
		if ($pid > 0) {
			foreach ($this->collections as &$col) {
				foreach ($col->threads as $k => &$t) {
					if ($t->pid === $pid) {
						$t->terminated = true;
						goto start;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Sends arbitrary signal to the process
	 * @param integer Signal's number
	 * @return boolean Success
	 */
	public function signal($sig) {
		return posix_kill($this->pid, $sig);
	}

	/**
	 * Checks if this process does exist
	 * @return boolean Success
	 */
	public function ifExists() {
		if (file_exists('/proc')) {
			return file_exists('/proc/' . $this->pid);
		}
		return posix_signal($this->pid, SIGTTIN);
	}


	/**
	 * Checks if given process ID does exist
	 * @static
	 * @param integer PID
	 * @return boolean Success
	 */
	public static function ifExistsByPid($pid) {
		if (file_exists('/proc')) {
			return file_exists('/proc/' . $pid);
		}
		return posix_signal($pid, SIGTTIN);
	}

	/**
	 * Waits until children is alive
	 * @return void
	 */
	protected function waitAll($check) {
		do {
			$n = 0;

			foreach ($this->collections as &$col) {
				$n += $col->removeTerminated($check);
			}
			if (!$this->waitPid()) {
				$this->sigwait(0, 20000);
			}
		} while ($n > 0);
	}

	/**
	 * Sets a title of the current process
	 * @param string Title
	 * @return boolean Success
	 */
	protected function setTitle($title) {
		if (is_callable('cli_set_process_title')) {
			return cli_set_process_title($title);
		}
		if (Daemon::loadModuleIfAbsent('proctitle')) {
			return setproctitle($title);
		}
		return false;
	}

	/**
	 * Returns a title of the current process
	 * @return string
	 */
	protected function getTitle() {
		if (is_callable('cli_get_process_title')) {
			return cli_get_process_title();
		}
		return false;
	}

	/**
	 * Waits for signals, with a timeout
	 * @param int Seconds
	 * @param int Nanoseconds
	 * @return boolean Success
	 */
	protected function sigwait($sec = 0, $nano = 0.3e9) {
		$siginfo = null;
		$signo = @pcntl_sigtimedwait(self::$signalsno, $siginfo, $sec, $nano);

		if (is_bool($signo)) {
			return $signo;
		} 

		if ($signo > 0) {
			$this->sighandler($signo);

			return true;
		}

		return false;
	}
}

if (!function_exists('pcntl_sigtimedwait')) { // For Mac OS where missing the orignal function
	function pcntl_sigtimedwait($signals, $siginfo, $sec, $nano) {
		pcntl_signal_dispatch();
		if (time_nanosleep($sec, $nano) === true) {
			return false;
		}
		pcntl_signal_dispatch();
		return true;
	}
}
