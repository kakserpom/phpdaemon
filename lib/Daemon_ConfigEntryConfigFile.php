<?php

/**
 * ConfigFile config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryConfigFile extends Daemon_ConfigEntry {

	public function onUpdate($old) {
		if (!Daemon::$process instanceof Daemon_MasterThread || (Daemon::$config->autoreload->value === 0) || !$old) {
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
