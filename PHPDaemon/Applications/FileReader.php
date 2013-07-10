<?php
namespace PHPDaemon\Applications;

	/**
	 * @package    Applications
	 * @subpackage FileReader
	 *
	 * @author     Zorin Vasily <maintainer@daemon.io>
	 */
/**
 * Class FileReader
 * @property array indexFiles
 * @package PHPDaemon\Applications
 */
class FileReader extends \PHPDaemon\Core\AppInstance {

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// index file names
			'indexfiles' => 'index.html/index.htm'
		];
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->onConfigUpdated();
	}

	/**
	 * Update indexFiles field when config is updated
	 */
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
