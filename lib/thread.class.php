<?php

abstract class Thread {
	public $spawnid;
	public $pid;
	public $shutdown = FALSE;
	public $terminated = FALSE;
	public $collections = array();

	public static $signalsno = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31);
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
	 * @method start
	 * @description Starts the process.
	 * @return void
	 */
	public function start() {
		$pid = pcntl_fork();

		if ($pid === -1) {
			throw new Exception('Could not fork');
		} else
		if ($pid == 0) {
			$this->pid = posix_getpid();

			foreach (Thread::$signals as $no => $name) {
				if (
					($name === 'SIGKILL') 
					|| ($name == 'SIGSTOP')
				) {
					continue;
				}

				if (!pcntl_signal($no, array($this, 'sighandler'), TRUE)) {
					throw new Exception('Cannot assign '.$name.' signal');
				}
			}

			$this->run();
			$this->shutdown();
		}

		$this->pid = $pid;
		return $pid;
	}

	/**
	 * @method sighandler
	 * @description Called when a signal caught.
	 * @param integer Signal's number.
	 * @return void
	 */
	public function sighandler($signo) {
		if (is_callable($c = array($this, strtolower(Thread::$signals[$signo])))) {
			call_user_func($c);
		}
		elseif (is_callable($c = array($this,'sigunknown'))) {
			call_user_func($c, $signo);
		}
	}

	/** 
	 * @method shutdown
	 * @description Shutdowns the current process properly.
	 * @return void
	 */
	public function shutdown() {
		posix_kill(posix_getppid(), SIGCHLD);
		exit(0);
	}

	/**
	 * @method backsig
	 * @description Semds the signal to parent process.
	 * @param integer Signal's number.
	 * @return void
	 */
	public function backsig($sig) {
		return posix_kill(posix_getppid(), $sig);
	}

	/**
	 * @method sleep
	 * @description Delays the process execution for the given number of seconds.
	 * @param integer Halt time in seconds.
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
	 * @method sigchld
	 * @description Called when the signal SIGCHLD caught.
	 * @return void
	 */
	public function sigchld() {
		$this->waitPid();
	}

	/**
	 * @method sigterm
	 * @description Called when the signal SIGTERM caught.
	 * @return void
	 */
	public function sigterm() {
		exit(0);
	}

	/**
	 * @method sigint
	 * @description Called when the signal SIGINT caught.
	 * @return void
	 */
	public function sigint() {
		exit(0);
	}

	/**
	 * @method sigquit
	 * @description Called when the signal SIGTERM caught.
	 * @return void
	 */
	public function sigquit() {
		$this->shutdown = TRUE;
	}

	/**
	 * @method sigkill
	 * @description Called when the signal SIGKILL caught.
	 * @return void
	 */
	public function sigkill() {
		exit(0);
	}

	/**
	 * @method stop
	 * @description Terminates the process.
	 * @param boolean Kill?
	 * @return void
	 */
	public function stop($kill = FALSE) {
		$this->shutdown = TRUE;

		return posix_kill($this->pid, $kill ? SIGKILL : SIGTERM);
	}

	/**
	 * @method waitPid
	 * @description Checks for SIGCHLD.
	 * @return boolean Success.
	 */
	public function waitPid() {
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
	 * @method signal
	 * @description Sends arbitrary signal to the process.
	 * @param integer Signal's number.
	 * @return boolean Success.
	 */
	public function signal($sig) {
		return posix_kill($this->pid, $sig);
	}

	/**
	 * @method waitAll
	 * @description Waits untill a children is alive.
	 * @return void
	 */
	public function waitAll() {
		do {
			$n = 0;

			foreach ($this->collections as &$col) {
				$n += $col->removeTerminated();
			}

			if (!$this->waitPid()) {
				$this->sigwait(0, 20000);
			}
		} while ($n > 0);
	}

	/**
	 * @method setproctitle
	 * @description Sets a title of the current process.
	 * @param string Title.
	 * @return void
	 */
	public static function setproctitle($title) {
		if (function_exists('setproctitle')) {
			return setproctitle($title);
		}

		return FALSE;
	}

	/**
	 * @method sigwait
	 * @description Waits for signals, with a timeout.
	 * @param int Seconds.
	 * @param int Nanoseconds.
	 * @return void
	 */
	public function sigwait($sec = 0, $nano = 1) {
		$siginfo = NULL;
		$signo = pcntl_sigtimedwait(Thread::$signalsno, $siginfo, $sec, $nano);

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
	function pcntl_sigtimedwait($signals, $siginfo, $sec, $nano) {
		pcntl_signal_dispatch();

		if (time_nanosleep($sec, $nano) === TRUE) {
			return FALSE;
		}

		pcntl_signal_dispatch();

		return TRUE;
	}
}
