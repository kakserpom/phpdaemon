<?php

/**
 * @package Examples
 * @subpackage UDPEchoServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class UDPEchoServer extends NetworkServer {

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'listen'				=> 'udp:0.0.0.0',
			'port' 			        => 1111,
		);
	}
	public function onConfigUpdated() {
		Daemon::log(get_class($this).'->onConfigUpdated(): '.json_encode($this->config));
		parent::onConfigUpdated();
	}
	
}

class UDPEchoServerConnection extends Connection {
	/**
	 * Called when UDP packet received.
	 * @param string New data.
	 * @return void
	 */
	public function onUdpPacket(pct) {
		$this->write('got: '.$pct);
	}
	
}
