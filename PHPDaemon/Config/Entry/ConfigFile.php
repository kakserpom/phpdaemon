<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Thread\Master;

/**
 * ConfigFile config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ConfigFile extends Generic {

	/**
	 * @param $old
	 */
	public function onUpdate($old) {
		if (!Daemon::$process instanceof Master || (Daemon::$config->autoreload->value === 0) || !$old) {
			return;
		}

		$e = explode(';', $old);
		foreach ($e as $path) {
			Daemon::$process->fileWatcher->rmWatch($path, [Daemon::$process, 'sighup']);
		}

		$e = explode(';', $this->value);
		foreach ($e as $path) {
			Daemon::$process->fileWatcher->addWatch($path, [Daemon::$process, 'sighup']);
		}
	}

}
