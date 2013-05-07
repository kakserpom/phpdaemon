<?php
namespace PHPDaemon;

use PHPDaemon\Core\AppInstance;

/**
 * TransportContext
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class TransportContext extends AppInstance {
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {

		}
	}
}
