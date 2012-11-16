<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class ExampleIRCBot extends AppInstance {
	public $client;
	public $conn;
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		$random = sprintf('%x', crc32(posix_getpid() . "\x00". microtime(true)));
		return array(
			'url' => 'irc://guest_'.$random.':password@hobana.freenode.net/Bot_phpDaemon'
		);
	}
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->client = IRCClient::getInstance();
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->client) {
			$this->client->onReady();
			$this->connect();
		}
	}
	
	public function connect() {
		$app = $this;
		$r = $this->client->getConnection($this->config->url->value, function ($conn) use ($app) {
			$app->conn = $conn;
			if ($conn->connected) {
				Daemon::log('IRC bot connected at '.$this->config->url->value);
				$conn->join('#botwar_phpdaemon');
				$conn->bind('motd', function($conn) {
					//Daemon::log($conn->motd);
				});
				$conn->bind('privateMsg', function($conn, $msg) {
					Daemon::log('IRCBot: got private message \''.$msg['body'].'\' from \''.$msg['from']['orig'].'\'');
					$conn->message($msg['from']['nick'], 'You just wrote: '.$msg['body']); // send the message back
				});
				$conn->bind('disconnect', function() use ($app) {
					$app->connect();
				});
			}
			else {
				Daemon::log('IRCBot: unable to connect ('.$this->config->url->value.')');
			}
		});
	}
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($this->client) {
			$this->client->config = $this->config;
			return $this->client->onConfigUpdated();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		if ($this->client) {
			return $this->client->onShutdown();
		}
		return true;
	}
}
