<?php
namespace PHPDaemon\Thread;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\ClearStack;

/**
 * Thread
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Generic {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Process identificator
	 * @var int
	 */
	public $id;

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
	 * @var array|Collection[]
	 */
	protected $collections = [];

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
		SIGINT   => 'SIGINT',
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
		SIGTSTP	  => 'SIGTSTP',
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

	protected function onTerminated() {}

	public function setTerminated() {
		$this->terminated = true;
		$this->onTerminated();
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
			$ev = \Event::signal($this->eventBase, $no, [$this, 'eventSighandler'], [$no]);
			if (!$ev) {
				$this->log('Cannot event_set for ' . $name . ' signal');
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
	 * @param mixed   Argument.
	 * @return void
	 */
	public function eventSighandler($fd, $arg) {
		$this->sighandler($arg[0]);
	}

	/**
	 * Run thread process
	 * @return void
	 */
	protected function run() {
	}

	/**
	 * If true, we do not register signals automatically at start
	 * @var boolean
	 */
	protected $delayedSigReg = false;

	/**
	 * Registers signals
	 * @return void
	 */
	protected function registerSignals() {
		foreach (self::$signals as $no => $name) {
			if (
					($name === 'SIGKILL')
					|| ($name == 'SIGSTOP')
			) {
				continue;
			}

			if (!\pcntl_signal($no, [$this, 'sighandler'], true)) {
				$this->log('Cannot assign ' . $name . ' signal');
			}
		}
	}

	/**
	 * Starts the process
	 * @return void
	 */
	public function start($clearstack = true) {
		$pid = \pcntl_fork();

		if ($pid === -1) {
			throw new \Exception('Could not fork');
		}
		elseif ($pid === 0) { // we are the child
			$thread      = $this;
			$thread->pid = \posix_getpid();
			if (!$thread->delayedSigReg) {
				$thread->registerSignals();
			}
			if ($clearstack) {
				throw new ClearStack('', 0, $thread);
			}
			else {
				$thread->run();
				$thread->shutdown();
			}
		}
		else { // we are the master
			$this->pid = $pid;
		}
	}

	/**
	 * Called when the signal is caught
	 * @param integer Signal's number
	 * @return void
	 */
	protected function sighandler($signo) {
		if (!isset(self::$signals[$signo])) {
			$this->log('caught unknown signal #' . $signo);
			return;
		}
		if (method_exists($this, $m = strtolower(self::$signals[$signo]))) {
			call_user_func([$this, $m]);
		}
		elseif (method_exists($this, 'sigunknown')) {
			call_user_func([$this, 'sigunknown'], $signo);
		}
	}

	/**
	 * Shutdowns the current process properly
	 * @return void
	 */
	protected function shutdown() {
		\posix_kill(\posix_getppid(), SIGCHLD);
		exit(0);
	}

	/**
	 * Sends the signal to parent process
	 * @param integer Signal's number
	 * @return boolean Success
	 */
	protected function backsig($sig) {
		return \posix_kill(\posix_getppid(), $sig);
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

			\usleep($interval * 1000000);
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
		\posix_kill($this->pid, $kill ? SIGKILL : SIGTERM);
	}

	/**
	 * Checks for SIGCHLD
	 * @return boolean Success
	 */
	protected function waitPid() {
		start:
		$pid = \pcntl_waitpid(-1, $status, WNOHANG);
		if ($pid > 0) {
			foreach ($this->collections as $col) {
				foreach ($col->threads as $k => $t) {
					if ($t->pid === $pid) {
						$t->setTerminated();
						unset($col->threads[$k]);
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
		return \posix_kill($this->pid, $sig);
	}

	/**
	 * Checks if this process does exist
	 * @return boolean Success
	 */
	public function ifExists() {
		return \posix_kill($this->pid, 0);
	}

	/**
	 * Checks if given process ID does exist
	 * @static
	 * @param integer PID
	 * @param integer $pid
	 * @return boolean Success
	 */
	public static function ifExistsByPid($pid) {
		return \posix_kill($pid, 0);
	}

	/**
	 * Waits until children is alive
	 * @param boolean $check
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
	 * @param string $title
	 * @return boolean Success
	 */
	protected function setTitle($title) {
		if (is_callable('cli_set_process_title')) {
			return \cli_set_process_title($title);
		}
		if (Daemon::loadModuleIfAbsent('proctitle')) {
			return \setproctitle($title);
		}
		return false;
	}

	/**
	 * Returns a title of the current process
	 * @return string
	 */
	protected function getTitle() {
		if (is_callable('cli_get_process_title')) {
			return \cli_get_process_title();
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

		if (!function_exists('pcntl_sigtimedwait')) {
			$signo   = $this->sigtimedwait(array_keys(static::$signals), $siginfo, $sec, $nano);
		} else {
			$signo   = @\pcntl_sigtimedwait(array_keys(static::$signals), $siginfo, $sec, $nano);
		}

		if (is_bool($signo)) {
			return $signo;
		}

		if ($signo > 0) {
			$this->sighandler($signo);

			return true;
		}

		return false;
	}

	/**
	 * Implementation of pcntl_sigtimedwait for Mac.
	 *
	 * @param array Signal
	 * @param null|array SigInfo
	 * @param int Seconds
	 * @param int Nanoseconds
	 * @param integer $sec
	 * @param double $nano
	 * @return boolean Success
	 */
	protected function sigtimedwait($signals, $siginfo, $sec, $nano) {
		\pcntl_signal_dispatch();
		if (\time_nanosleep($sec, $nano) === true) {
			return false;
		}
		\pcntl_signal_dispatch();
		return true;
	}
}
