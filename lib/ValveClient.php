<?php
/**
 * @package NetworkClients
 * @subpackage HLClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
// https://developer.valvesoftware.com/wiki/Server_queries
class ValveClient extends NetworkClient {
	const A2S_INFO = "\x54";
	const S2A_INFO = "\x49";
	const S2A_INFO_SOURCE = "\x6d";
	const A2S_PLAYER = "\x55";
	const S2A_PLAYER = "\x44";
	const A2S_SERVERQUERY_GETCHALLENGE = "\x57";
	const S2A_SERVERQUERY_GETCHALLENGE = "\x41";
	const A2A_PING = "\x69";
	const S2A_PONG = "\x6A";
	
	public static $zombie = 0;

	public function request($addr, $name, $data,  $cb) {
		$e = explode(':', $addr);
		$this->getConnection('valve://[udp:' . $e[0] . ']' . (isset($e[1]) ? ':'.$e[1] : '') . '/', function($conn) use ($cb, $addr, $data, $name) {
			if (!$conn->connected) {
				call_user_func($cb, $conn, false);
				return;
			}
			$conn->request($name, $data, $cb);
		});
	}
	

	public function ping($addr, $cb) {
		$e = explode(':', $addr);
		$this->getConnection('valve://[udp:' . $e[0] . ']' . (isset($e[1]) ? ':'.$e[1] : '') . '/ping', function($conn) use ($cb) {
			if (!$conn->connected) {
				call_user_func($cb, $conn, false);
				return;
			}
			$mt = microtime(true);
			$conn->request('ping', null, function ($conn, $success) use ($mt, $cb) {
				call_user_func($cb, $conn, $success ? (microtime(true) - $mt) : false);
			});
		});
	}

	public function requestInfo($addr, $cb) {
		$this->request($addr, 'info', null, $cb);
	}

	public function requestPlayers($addr, $cb) {
		$this->request($addr, 'challenge', null, function ($conn, $result) use ($cb) {
			if (is_array($result)) {
				$cb($conn, $result);
				return;
			}
			$conn->request('players', $result, $cb);
		});
	}

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'servers'               =>  '127.0.0.1',
			'port'					=> 27015,
			'maxconnperserv'		=> 32,
		);
	}
}
class ValveClientConnection extends NetworkClientConnection {
	public $timeout = 1;

	public function requestPlayers($cb) {
		$this->request('challenge', null, function ($conn, $result) use ($cb) {
			if (is_array($result)) {
				call_user_func($cb, $conn, $result);
				return;
			}
			$conn->request('players', $result, $cb);
		});
	}
	
	public function requestInfo($cb) {
		$this->request('info', null, $cb);
	}

	public function request($name, $data = null, $cb = null) {
		$packet = "\xFF\xFF\xFF\xFF";
		if ($name === 'ping') {
			$packet .= ValveClient::A2A_PING;
		} elseif ($name === 'challenge') {
			//$packet .= ValveClient::A2S_SERVERQUERY_GETCHALLENGE;
			$packet .= ValveClient::A2S_PLAYER . "\xFF\xFF\xFF\xFF";
		} elseif ($name === 'info') {
			$packet .= ValveClient::A2S_INFO . "Source Engine Query\x00";
			//"\xFF\xFF\xFF\xFFdetails\x00"
		} elseif ($name === 'players') {
			if ($data === null) {
				$data = "\xFF\xFF\xFF\xFF";
			}
			$packet .= ValveClient::A2S_PLAYER . $data;
		} else {
			return false;
		}
		$this->onResponse->push($cb);
		$this->setFree(false);
		//Daemon::log('packet: '.Debug::exportBytes($packet, true));
   		$this->write($packet);
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		//Daemon::log('stdin: '.Debug::exportBytes($buf, true));
		$this->buf .= $buf;
		start:
		if (strlen($this->buf) < 5) {
			return;
		}
		$h = Binary::getDWord($this->buf);
		if ($h !== 0xFFFFFFFF) {
			$this->finish();
			return;
		}
		$type = Binary::getChar($this->buf);
		if (($type === ValveClient::S2A_INFO) || ($type === ValveClient::S2A_INFO_SOURCE)) {
			$result = $this->parseInfo($this->buf, $type);
		}
		elseif ($type === ValveClient::S2A_PLAYER) {
			$result = $this->parsePlayers($this->buf);
		}
		elseif ($type === ValveClient::S2A_SERVERQUERY_GETCHALLENGE) {
			$result = binarySubstr($this->buf, 0, 4);
			$this->buf = binarySubstr($this->buf, 5);
		}
		elseif ($type === ValveClient::S2A_PONG) {
			$result = true;
		}
		else {
			$result = null;
		}
		$this->onResponse->executeOne($this, $result);
		$this->checkFree();
		goto start;
	}

	public function parsePlayers(&$st) {
		$playersn = Binary::getByte($st);
		$players = array();
		for ($i = 1; $i < $playersn; ++$i) {
			$n = Binary::getByte($st);
			$name = Binary::getString($st);
			$score = Binary::getDWord($st,TRUE);
			if (strlen($st) === 0) {
				break;
			}
			$u = unpack('f', binarySubstr($st, 0, 4));
			$st = binarySubstr($st, 4);
			$seconds = $u[1];
			if ($seconds == -1) {
				continue;
			}
			$players[] = array(
				'name' => Encoding::toUTF8($name),
				'score' => $score,
				'seconds' => $seconds,
				'joinedts' => microtime(true) - $seconds,
				'spm' => $score / ($seconds / 60),
			);
		}
		return $players;
	}

	public function parseInfo(&$st, $type) {
		$info = array();
		if ($type === ValveClient::S2A_INFO) {
			$info['proto'] = Binary::getByte($st);
			$info['hostname'] = Binary::getString($st);
			$info['map'] = Binary::getString($st);
			$info['gamedir'] = Binary::getString($st);
			$info['gamedescr'] = Binary::getString($st);
			$info['steamid'] = Binary::getWord($st);
			$info['playersnum'] = Binary::getByte($st);
			$info['playersmax'] = Binary::getByte($st); 
			$info['botcount'] = Binary::getByte($st); 
			$info['servertype'] = Binary::getChar($st); 
			$info['serveros'] = Binary::getChar($st); 
			$info['passworded'] = Binary::getByte($st); 
			$info['secure'] = Binary::getByte($st); 
   		}
   		elseif ($type === ValveClient::S2A_INFO_SOURCE) {
    		$info['srvaddress'] = Binary::getString($st);
    		$info['hostname'] = Binary::getString($st);
    		$info['map'] = Binary::getString($st);
    		$info['gamedir'] = Binary::getString($st);
    		$info['gamedescr'] = Binary::getString($st);
			$info['playersnum'] = Binary::getByte($st);
			$info['playersmax'] = Binary::getByte($st); 
			$info['proto'] = Binary::getByte($st);
			$info['servertype'] = Binary::getChar($st); 
			$info['serveros'] = Binary::getChar($st); 
			$info['passworded'] = Binary::getByte($st); 
			$info['modded'] = Binary::getByte($st); 
			if ($info['modded']) {
				$info['mod_website'] = Binary::getString($st);
				$info['mod_downloadserver'] = Binary::getString($st);
				$info['mod_unused'] = Binary::getString($st);
				$info['mod_version'] = Binary::getDWord($st,TRUE);
				$info['mod_size'] = Binary::getDWord($st);
				$info['mod_serverside'] = Binary::getByte($st);
				$info['mod_customdll'] = Binary::getByte($st);
    		}
			$info['secure'] = Binary::getByte($st);
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