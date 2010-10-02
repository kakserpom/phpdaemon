<?php
class LockClient extends AsyncServer {

	public $sessions = array();     // Active sessions
	public $servers = array();      // Array of servers
	public $servConn = array();     // Active connections
	public $prefix = '';            // Prefix
	public $jobs = array();         // Active jobs
	public $dtags_enabled = FALSE;  // Enables tags for distibution

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'servers' => '127.0.0.1',
			'port'    => 833,
			'prefix'  => '',
		));

		$this->prefix = $this->config->prefix->value;
		$servers = explode(',', $this->config->servers->value);

		foreach ($servers as $s) {
			$e = explode(':',$s);
			$this->addServer($e[0], isset($e[1]) ? $e[1] : NULL);
		}
	}

	/**
	 * @method addServer
	 * @description Adds memcached server.
	 * @param string Server's host.
	 * @param string Server's port.
	 * @param integer Weight.
	 * @return void
	*/
	public function addServer($host, $port = NULL, $weight = NULL) {
		if ($port === NULL) {
			$port = $this->config->port->value;
		}

		$this->servers[$host . ':' . $port] = $weight;
	}

	/**
	 * @method job
	 * @description Runs a job.
	 * @param string Name of job.
	 * @param callback onRun. Job's runtime.
	 * @param callback onSuccess. Called when job successfully done.
	 * @param callback onFailure. Called when job failed.
	 * @param integer Weight.
	 * @return void
	 */
	public function job($name, $wait, $onRun, $onSuccess = NULL, $onFailure = NULL) {
		$name = $this->prefix . $name;
		$connId = $this->getConnectionByName($name);

		if (!isset($this->sessions[$connId])) {
			return;
		}

		$sess = $this->sessions[$connId];
		$this->jobs[$name] = array($onRun, $onSuccess, $onFailure);
		$sess->writeln('acquire' . ($wait ? 'Wait' : '') . ' ' . $name);
	}

	/**
	 * @method done
	 * @description Sends done-event.
	 * @param string Name of job.
	 * @return void
	 */
	public function done($name) {
		$connId = $this->getConnectionByName($name);
		$sess = $this->sessions[$connId];
		$sess->writeln('done ' . $name);
	}

	/**
	 * @method failed
	 * @description Sends failed-event.
	 * @param string Name of job.
	 * @return void
	 */
	public function failed($name) {
		$connId = $this->getConnectionByName($name);
		$sess = $this->sessions[$connId];
		$sess->writeln('failed ' . $name);
	}

	/**
	 * @method getConnection
	 * @description Establishes connection.
	 * @param string Address.
	 * @return integer Connection's ID.
	 */
	public function getConnection($addr) {
		if (isset($this->servConn[$addr])) {
			foreach ($this->servConn[$addr] as &$c) {
				return $c;
			}
		} else {
			$this->servConn[$addr] = array();
		}

		$e = explode(':', $addr);

		$connId = $this->connectTo($e[0], $e[1]);

		$this->sessions[$connId] = new LockClientSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
		$this->servConn[$addr][$connId] = $connId;

		return $connId;
	}

	/**
	 * @method getConnectionByName
	 * @description Returns available connection from the pool by name.
	 * @param string Key.
	 * @return object MemcacheSession
	 */
	public function getConnectionByName($name) {
		if (
			($this->dtags_enabled) 
			&& (($sp = strpos($name, '[')) !== FALSE) 
			&& (($ep = strpos($name, ']')) !== FALSE) 
			&& ($ep > $sp)
		) {
			$name = substr($name,$sp + 1, $ep - $sp - 1);
		}

		srand(crc32($name));
		$addr = array_rand($this->servers);
		srand();  

		return $this->getConnection($addr);
	}
}

class LockClientSession extends SocketSession {

	public $addr;               // Address
	public $finished = FALSE;   // Is this session finished?

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		while (($l = $this->gets()) !== FALSE) {
			$e = explode(' ', rtrim($l, "\r\n"));

			if ($e[0] === 'RUN') {
				if (isset($this->appInstance->jobs[$e[1]])) {
					call_user_func($this->appInstance->jobs[$e[1]][0], $e[0], $e[1], $this->appInstance);
				}
			}
			elseif ($e[0] === 'DONE') {
				if (isset($this->appInstance->jobs[$e[1]][1])) {
					call_user_func($this->appInstance->jobs[$e[1]][1], $e[0], $e[1], $this->appInstance);
				}
			}
			elseif ($e[0] === 'FAILED') {
				if (isset($this->appInstance->jobs[$e[1]][2])) {
					call_user_func($this->appInstance->jobs[$e[1]][2], $e[0], $e[1], $this->appInstance);
				}
			}
		}
	}

	/**
	 * @method onFinish
	 * @description Called when session finishes.
	 * @return void
	 */
	public function onFinish() {
		$this->finished = TRUE;

		unset($this->appInstance->servConn[$this->addr][$this->connId]);
		unset($this->appInstance->sessions[$this->connId]);
	}
}
