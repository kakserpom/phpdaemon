<?php
namespace PHPDaemon\Servers\IRCBouncer;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\Server;
use PHPDaemon\Structures\ObjectStorage;

class Pool extends Server {
	/** @var */
	public $client;
	/** @var */
	public $conn;
	/** @var bool */
	public $protologging = false;
	/** @var */
	public $db;
	/** @var */
	public $messages;
	/** @var */
	public $channels;

	/**
	 * @TODO DESCR
	 */
	protected function init() {
		$this->client               = Pool::getInstance();
		$this->client->protologging = $this->protologging;
		$this->db                   = Pool::getInstance();
		$this->messages             = $this->db->{$this->config->dbname->value . '.messages'};
		$this->channels             = $this->db->{$this->config->dbname->value . '.channels'};
	}

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'listen'          => '0.0.0.0',
			'port'            => 6667,
			'url'             => 'irc://user@host/nickname/realname',
			'servername'      => 'bnchost.tld',
			'defaultchannels' => '',
			'protologging'    => 0,
			'dbname'          => 'bnc',
			'password'        => 'SecretPwd',
		];
	}

	/**
	 * @TODO DESCR
	 */
	public function applyConfig() {
		parent::applyConfig();
		$this->protologging = (bool)$this->config->protologging->value;
		if (isset($this->client)) {
			$this->client->protologging = $this->protologging;
		}
	}

	/**
	 * @TODO DESCR
	 */
	public function onReady() {
		parent::onReady();
		$this->client->onReady();
		$this->getConnection($this->config->url->value);
	}

	/**
	 * @TODO DESCR
	 * @param string $url
	 */
	public function getConnection($url) {
		$this->client->getConnection($url, function ($conn) use ($url) {
			$this->conn            = $conn;
			$conn->attachedClients = new ObjectStorage;
			if ($conn->connected) {
				Daemon::log('IRC bot connected at ' . $url);
				$conn->join($this->config->defaultchannels->value);
				$conn->bind('motd', function ($conn) {
					//Daemon::log($conn->motd);
				});
				foreach ($this as $bouncerConn) {
					if (!$bouncerConn->attachedServer) {
						$bouncerConn->attachTo($conn);
					}
				}
				$conn->bind('command', function ($conn, $from, $cmd, $args) {
					if ($cmd === 'PONG') {
						return;
					}
					elseif ($cmd === 'PING') {
						return;
					}
					if ($from['orig'] === $conn->servername) {
						$from['orig'] = $this->config->servername->value;
					}
					$conn->attachedClients->each('commandArr', $from['orig'], $cmd, $args);
				});
				$conn->bind('privateMsg', function ($conn, $msg) {
					Daemon::log('IRCBouncer: got private message \'' . $msg['body'] . '\' from \'' . $msg['from']['orig'] . '\'');
				});
				$conn->bind('msg', function ($conn, $msg) {
					$msg['ts']  = microtime(true);
					$msg['dir'] = 'i';
					$this->messages->insert($msg);
				});
				$conn->bind('disconnect', function ($conn) use ($url) {
					foreach ($this as $bouncerConn) {
						if ($bouncerConn->attachedServer === $conn) {
							$bouncerConn->detach();
						}
					}
					$this->getConnection($url);
				});
			}
			else {
				Daemon::log('IRCBouncer: unable to connect (' . $url . ')');
			}
		});
	}
}
