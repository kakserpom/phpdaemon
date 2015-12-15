<?php
namespace PHPDaemon\FS;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Timer;

/**
 * Implementation of the file watcher
 * @package PHPDaemon\FS
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class FileWatcher {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var array Associative array of the files being observed
	 */
	public $files = [];

	/**
	 * @var resource Resource returned by inotify_init()
	 */
	public $inotify;

	/**
	 * @var Array of inotify descriptors
	 */
	public $descriptors = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		if (Daemon::loadModuleIfAbsent('inotify')) {
			$this->inotify = inotify_init();
			stream_set_blocking($this->inotify, 0);
		}

		Timer::add(function ($event) {

			Daemon::$process->fileWatcher->watch();
			if (sizeof(Daemon::$process->fileWatcher->files) > 0) {
				$event->timeout();
			}

		}, 1e6 * 1, 'fileWatcher');

	}

	/**
	 * Adds your subscription on object in FS
	 * @param  string  $path	Path
	 * @param  mixed   $cb		Callback
	 * @param  integer $flags	Look inotify_add_watch()
	 * @return true
	 */
	public function addWatch($path, $cb, $flags = null) {
		$path = realpath($path);
		if (!isset($this->files[$path])) {
			$this->files[$path] = [];
			if ($this->inotify) {
				$this->descriptors[inotify_add_watch($this->inotify, $path, $flags ? : IN_MODIFY)] = $path;
			}
		}
		$this->files[$path][] = $cb;
		Timer::setTimeout('fileWatcher');
		return true;
	}

	/**
	 * Cancels your subscription on object in FS
	 * @param  string  $path	Path
	 * @param  mixed   $cb		Callback
	 * @return boolean
	 */
	public function rmWatch($path, $cb) {

		$path = realpath($path);

		if (!isset($this->files[$path])) {
			return false;
		}
		if (($k = array_search($cb, $this->files[$path], true)) !== false) {
			unset($this->files[$path][$k]);
		}
		if (sizeof($this->files[$path]) === 0) {
			if ($this->inotify) {
				if (($descriptor = array_search($path, $this->descriptors)) !== false) {
					inotify_rm_watch($this->inotify, $cb);
				}
			}
			unset($this->files[$path]);
		}
		return true;
	}

	/**
	 * Called when file $path is changed
	 * @param  string $path Path
	 * @return void
	 */
	public function onFileChanged($path) {
		if (!Daemon::lintFile($path)) {
			Daemon::log(__METHOD__ . ': Detected parse error in ' . $path);
			return;
		}
		foreach ($this->files[$path] as $cb) {
			if (is_callable($cb) || is_array($cb)) {
				call_user_func($cb, $path);
			}
			elseif (!Daemon::$process->IPCManager->importFile($cb, $path)) {
				$this->rmWatch($path, $cb);
			}
		}
	}

	/**
	 * Check the file system, triggered by timer
	 * @return void
	 */
	public function watch() {
		if ($this->inotify) {
			$events = inotify_read($this->inotify);
			if (!$events) {
				return;
			}
			foreach ($events as $ev) {
				$path = $this->descriptors[$ev['wd']];
				if (!isset($this->files[$path])) {
					continue;
				}
				$this->onFileChanged($path);
			}
		}
		else {
			static $hash = [];

			foreach ($this->files as $path => $v) {
				if (!file_exists($path)) {
					// file can be deleted
					unset($this->files[$path]);
					continue;
				}

				$mt = filemtime($path);

				if (
						isset($hash[$path])
						&& ($mt > $hash[$path])
				) {
					$this->onFileChanged($path);
				}

				$hash[$path] = $mt;
			}
		}
	}
}
