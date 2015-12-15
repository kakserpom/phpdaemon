<?php
namespace PHPDaemon\Clients\Valve;

use PHPDaemon\Clients\Valve\Pool;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;
use PHPDaemon\Utils\Encoding;

/**
 * @package    NetworkClients
 * @subpackage HLClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {
	/**
	 * @var integer Timeout
	 */
	public $timeout = 1;

	/**
	 * Sends a request of type 'players'
	 * @param  callable $cb Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function requestPlayers($cb) {
		$this->request('challenge', null, function ($conn, $result) use ($cb) {
			if (is_array($result)) {
				call_user_func($cb, $conn, $result);
				return;
			}
			$conn->request('players', $result, $cb);
		});
	}

	/**
	 * Sends a request of type 'info'
	 * @param  callable $cb Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function requestInfo($cb) {
		$this->request('info', null, $cb);
	}

	/**
	 * Sends a request
	 * @param  string   $type Type of request
	 * @param  string   $data Data
	 * @param  callable $cb   Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function request($type, $data = null, $cb = null) {
		$packet = "\xFF\xFF\xFF\xFF";
		if ($type === 'ping') {
			$packet .= Pool::A2A_PING;
		}
		elseif ($type === 'challenge') {
			//$packet .= ValveClient::A2S_SERVERQUERY_GETCHALLENGE;
			$packet .= Pool::A2S_PLAYER . "\xFF\xFF\xFF\xFF";
		}
		elseif ($type === 'info') {
			$packet .= Pool::A2S_INFO . "Source Engine Query\x00";
			//"\xFF\xFF\xFF\xFFdetails\x00"
		}
		elseif ($type === 'players') {
			if ($data === null) {
				$data = "\xFF\xFF\xFF\xFF";
			}
			$packet .= Pool::A2S_PLAYER . $data;
		}
		else {
			return null;
		}
		$this->onResponse->push($cb);
		$this->setFree(false);
		//Daemon::log('packet: '.Debug::exportBytes($packet, true));
		$this->write($packet);
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	protected function onRead() {
		start:
		if ($this->getInputLength() < 5) {
			return;
		}
		/* @TODO: refactoring Binary::* to support direct buffer calls */
		$pct = $this->read(4096);
		$h   = Binary::getDWord($pct);
		if ($h !== 0xFFFFFFFF) {
			$this->finish();
			return;
		}
		$type = Binary::getChar($pct);
		if (($type === Pool::S2A_INFO) || ($type === Pool::S2A_INFO_SOURCE)) {
			$result = self::parseInfo($pct, $type);
		}
		elseif ($type === Pool::S2A_PLAYER) {
			$result = self::parsePlayers($pct);
		}
		elseif ($type === Pool::S2A_SERVERQUERY_GETCHALLENGE) {
			$result = binarySubstr($pct, 0, 4);
			$pct    = binarySubstr($pct, 5);
		}
		elseif ($type === Pool::S2A_PONG) {
			$result = true;
		}
		else {
			$result = null;
		}
		$this->onResponse->executeOne($this, $result);
		$this->checkFree();
		goto start;
	}

	/**
	 * Parses response to 'players' command into structure
	 * @param  string &$st Data
	 * @return array       Structure
	 */
	public static function parsePlayers(&$st) {
		$playersn = Binary::getByte($st);
		$players  = [];
		for ($i = 1; $i < $playersn; ++$i) {
			$n     = Binary::getByte($st);
			$name  = Binary::getString($st);
			$score = Binary::getDWord($st, TRUE);
			if (strlen($st) === 0) {
				break;
			}
			$u       = unpack('f', binarySubstr($st, 0, 4));
			$st      = binarySubstr($st, 4);
			$seconds = $u[1];
			if ($seconds === -1) {
				continue;
			}
			$players[] = [
				'name'     => Encoding::toUTF8($name),
				'score'    => $score,
				'seconds'  => $seconds,
				'joinedts' => microtime(true) - $seconds,
				'spm'      => $score / ($seconds / 60),
			];
		}
		return $players;
	}

	/**
	 * Parses response to 'info' command into structure
	 * @param  string &$st  Data
	 * @param  string $type Type of request
	 * @return array        Structure
	 */
	public static function parseInfo(&$st, $type) {
		$info = [];
		if ($type === Pool::S2A_INFO) {
			$info['proto']      = Binary::getByte($st);
			$info['hostname']   = Binary::getString($st);
			$info['map']        = Binary::getString($st);
			$info['gamedir']    = Binary::getString($st);
			$info['gamedescr']  = Binary::getString($st);
			$info['steamid']    = Binary::getWord($st);
			$info['playersnum'] = Binary::getByte($st);
			$info['playersmax'] = Binary::getByte($st);
			$info['botcount']   = Binary::getByte($st);
			$info['servertype'] = Binary::getChar($st);
			$info['serveros']   = Binary::getChar($st);
			$info['passworded'] = Binary::getByte($st);
			$info['secure']     = Binary::getByte($st);
		}
		elseif ($type === Pool::S2A_INFO_SOURCE) {
			$info['srvaddress'] = Binary::getString($st);
			$info['hostname']   = Binary::getString($st);
			$info['map']        = Binary::getString($st);
			$info['gamedir']    = Binary::getString($st);
			$info['gamedescr']  = Binary::getString($st);
			$info['playersnum'] = Binary::getByte($st);
			$info['playersmax'] = Binary::getByte($st);
			$info['proto']      = Binary::getByte($st);
			$info['servertype'] = Binary::getChar($st);
			$info['serveros']   = Binary::getChar($st);
			$info['passworded'] = Binary::getByte($st);
			$info['modded']     = Binary::getByte($st);
			if ($info['modded']) {
				$info['mod_website']        = Binary::getString($st);
				$info['mod_downloadserver'] = Binary::getString($st);
				$info['mod_unused']         = Binary::getString($st);
				$info['mod_version']        = Binary::getDWord($st, TRUE);
				$info['mod_size']           = Binary::getDWord($st);
				$info['mod_serverside']     = Binary::getByte($st);
				$info['mod_customdll']      = Binary::getByte($st);
			}
			$info['secure']  = Binary::getByte($st);
			$info['botsnum'] = Binary::getByte($st);
		}
		foreach ($info as &$val) {
			if (is_string($val)) {
				$val = Encoding::toUTF8($val);
			}
		}
		return $info;
	}
}
