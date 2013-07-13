<?php
namespace PHPDaemon\Applications\GibsonREST;

/**
 * @package    GibsonREST
 * @subpackage Base
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class App extends \PHPDaemon\Core\AppInstance {

	public $gibson;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'gibson-name' => '',
		];
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->gibson = \PHPDaemon\Clients\Gibson\Pool::getInstance($this->config->gibsonname->value);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new Request($this, $upstream, $req);
	}
}
