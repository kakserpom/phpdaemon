<?php

/**
 * Thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class Thread {

	public $id;

	/**
	 * Process identificator
	 * @var int
	 */
	public $pid;

	/**
	 * @todo Add a description
	 * @var boolean
	 */
	public $shutdown = FALSE;

	/**
	 * @todo Add a description
	 * @var boolean
	 */
	public $terminated = FALSE;

	/**
	 * @todo Add a description
	 * @var array
	 */
	public $collections = array();

	public $timeouts = array();

	private static $signalsno = array(
		1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 
		18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31
	);
	
	public $sigEvents = array();
	
	public static $signals = array(
		SIGHUP    => 'SIGHUP',
		SIGINT    => 'SIGINT',
		SIGQUIT   => 'SIGQUIT',
		SIGILL    => 'SIGILL',
		SIGTRAP   => 'SIGTRAP',
		SIGABRT   => 'SIGABRT',
		7         => 'SIGEMT',
		SIGFPE    => 'SIGFPE',
		SIGKILL   => 'SIGKILL',
		SIGBUS    => 'SIGBUS',
		SIGSEGV   => 'SIGSEGV',
		SIGSYS    => 'SIGSYS',
		SIGPIPE   => 'SIGPIPE',
		SIGALRM   => 'SIGALRM',
		SIGTERM   => 'SIGTERM',
		SIGURG    => 'SIGURG',
		SIGSTOP   => 'SIGSTOP',
		SIGTSTP   => 'SIGTSTP',
		SIGCONT   => 'SIGCONT',
		SIGCHLD   => 'SIGCHLD',
		SIGTTIN   => 'SIGTTIN',
		SIGTTOU   => 'SIGTTOU',
		SIGIO     => 'SIGIO',
		SIGXCPU   => 'SIGXCPU',
		SIGXFSZ   => 'SIGXFSZ',
		SIGVTALRM => 'SIGVTALRM',
		SIGPROF   => 'SIGPROF',
		SIGWINCH  => 'SIGWINCH',
		28        => 'SIGINFO',
		SIGUSR1   => 'SIGUSR1',
		SIGUSR2   => 'SIGUSR2',
	);
	
	
	/**
	 * Register signals.
	 * @return void
	 */
	public function registerEventSignals()
	{
		foreach (self::$signals as $no => $name) {
			if (
				($name === 'SIGKILL') 
				|| ($name == 'SIGSTOP')
			) {
				continue;
			}
			
			$ev = event_new();
			if (
				!event_set(
					$ev,
					$no,
					EV_SIGNAL | EV_PERSIST,
					array($this,'eventSighandler'),
					array($no)
				)
			) {
				throw new Exception('Cannot event_set for '.$name.' signal');
			}
			
			event_base_set($ev, $this->eventBase);
			event_add($ev);
			$this->sigEvents[$no] = $ev;
		}
	}

	/**
	 * Called when a signal caught through libevent.
	 * @param integer Signal's number.
	 * @param integer Events.
	 * @param mixed Argument.
	 * @return void
	 */
	public function eventSighandler($fd, $events, $arg) {
	  $this->sighandler($arg[0]);
	}

	/**
	 * Run thread process
	 * @return void
	 */
	public function run() { }

	/**
	 * @todo Add a description
	 * @var boolean
	 */
	public $delayedSigReg = FALSE;

	/**
	 * Registers signals
	 * @return void
	 */
	public function registerSignals()
	{
		foreach (self::$signals as $no => $name) {
			if (
				($name === 'SIGKILL') 
				|| ($name == 'SIGSTOP')
			) {
				continue;
			}

			if (!pcntl_signal($no, array($this, 'sighandler'), TRUE)) {
				throw new Exception('Cannot assign ' . $name . ' signal');
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
			return $pid;
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
	public function shutdown() {
		posix_kill(posix_getppid(), SIGCHLD);
		exit(0);
	}

	/**
	 * Sends the signal to parent process
	 * @param integer Signal's number
	 * @return void
	 */
	private function backsig($sig) {
		return posix_kill(posix_getppid(), $sig);
	}

	/**
	 * Delays the process execution for the given number of seconds
	 * @param integer Sleep time in seconds
	 * @return void
	 */
	public function sleep($s) {
		static $interval = 0.2;
		$n = $s / $interval;

		for ($i = 0; $i < $n; ++$i) {
			if ($this->shutdown) {
				return FALSE;
			}

			usleep($interval * 1000000);
		}

		return TRUE;
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

		return posix_kill($this->pid, $kill ? SIGKILL : SIGTERM);
	}

	/**
	 * Checks for SIGCHLD
	 * @return boolean Success
	 */
	private function waitPid() {
		$pid = pcntl_waitpid(-1, $status, WNOHANG);
		if ($pid > 0) {
			foreach ($this->collections as &$col) {
				foreach ($col->threads as $k => &$t) {
					if ($t->pid === $pid) {
						$t->terminated = TRUE;
						return TRUE;
					}
				}
			}
		}

		return FALSE;
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
	 * @return void
	 */
	protected function setproctitle($title) {
		if (Daemon::loadModuleIfAbsent('proctitle')) {
			return setproctitle($title);
		}

		return FALSE;
	}

	/**
	 * Waits for signals, with a timeout
	 * @param int Seconds
	 * @param int Nanoseconds
	 * @return void
	 */
	protected function sigwait($sec = 0, $nano = 1) {
		$siginfo = NULL;
		$signo = pcntl_sigtimedwait(self::$signalsno, $siginfo, $sec, $nano);

		if (is_bool($signo)) {
			return $signo;
		} 

		if ($signo > 0) {
			$this->sighandler($signo);

			return TRUE;
		}

		return FALSE;
	}
}

if (!function_exists('pcntl_sigtimedwait')) {
	// @todo $signals or Thread::$signals?
	function pcntl_sigtimedwait($signals, $siginfo, $sec, $nano) {
		pcntl_signal_dispatch();

		if (time_nanosleep($sec, $nano) === TRUE) {
			return FALSE;
		}

		pcntl_signal_dispatch();

		return TRUE;
	}
}
