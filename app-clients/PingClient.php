<?php

/**
 * @package Applications
 * @subpackage PingClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class PingClient extends AsyncServer {

	public $sessions = array();     // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array();
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
	}


}
