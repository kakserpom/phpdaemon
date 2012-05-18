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

	/**
	 * Establishes connection
	 * @param string Address
	 * @return integer Connection's ID
	 */
	public function sendPing($host, $cb) {

		$connId = $this->connectTo('raw:' . $host);

		$this->sessions[$connId] = new PingClientSession($connId, $this);
		$this->sessions[$connId]->host = $host;
		++$this->sessions[$connId]->seq;
		
		$this->sessions[$connId]->sendEcho($cb);

		return $connId;
	}
}

class PingClientSession extends SocketSession {

	public $seq = 0;
	public $callbacks = array();
	public $busy = false;
	
	public function sendEcho($cb) {
		++$this->seq;
		
		$packet = pack('ccnnn', 8, 0, 0, Daemon::$process->pid,	$this->seq) . "PingHost";
		$packet = substr_replace($packet, self::checksum($packet), 2, 2);
		$this->write($packet);
		$this->callbacks[] = array($cb, microtime(true));
	}
		
	public static function checksum($data) {
		$bit = unpack('n*', $data);
		$sum = array_sum($bit);
		if (strlen($data) % 2) {
			$temp = unpack('C*', $data[strlen($data) - 1]);
			$sum += $temp[1];
		}
		$sum = ($sum >> 16) + ($sum & 0xffff);
		$sum += ($sum >> 16);
		return pack('n*', ~$sum);
	}
	

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {		
		while ($c = array_pop($this->callbacks)) {
		 list ($cb, $st) = $c;
		 $cb(microtime(true) - $st);
		}
		
	}

}
