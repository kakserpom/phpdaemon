<?php
namespace PHPDaemon\Daemon;

use PHPDaemon\Daemon;

/**
 * ConfigFile config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ConfigEntryConfigFile extends ConfigEntry {

	public function onUpdate($old) {
		if (!Daemon::$process instanceof MasterThread || (Daemon::$config->autoreload->value === 0) || !$old) {
			return;
		}

		$e = explode(';', $old);
		foreach ($e as $path) {
			Daemon::$process->fileWatcher->rmWatch($path);
		}

		$e = explode(';', $this->value);
		foreach ($e as $path) {
			Daemon::$process->fileWatcher->addWatch($path, [Daemon::$process, 'sighup']);
		}
	}

}
