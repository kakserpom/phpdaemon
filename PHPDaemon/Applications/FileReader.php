<?php
namespace PHPDaemon\Applications;

/**
 * @package    Applications
 * @subpackage FileReader
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class FileReader extends \PHPDaemon\Core\AppInstance {

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// index file names
			'indexfiles' => 'index.html/index.htm'
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->onConfigUpdated();
	}

	public function onConfigUpdated() {
		$this->indexFiles = explode('/', $this->config->indexfiles->value);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new \PHPDaemon\Applications\FileReaderRequest($this, $upstream, $req);
	}
}
