<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage UDPEchoServer
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class UDPEchoServer extends \PHPDaemon\Network\Server {

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'listen' => 'udp://0.0.0.0',
			'port'   => 1111,
		];
	}

	public function onConfigUpdated() {
		parent::onConfigUpdated();
	}

}

class UDPEchoServerConnection extends \PHPDaemon\Network\Connection {
	/**
	 * Called when UDP packet received.
	 * @param string New data.
	 * @return void
	 */
	public function onUdpPacket($pct) {
		$this->write('got: ' . $pct);
	}

}
