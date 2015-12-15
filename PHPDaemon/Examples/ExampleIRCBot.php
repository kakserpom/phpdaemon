<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */

class ExampleIRCBot extends \PHPDaemon\Core\AppInstance {
	public $client;
	public $conn;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		$random = sprintf('%x', crc32(posix_getpid() . "\x00" . microtime(true)));
		return [
			'url' => 'irc://guest_' . $random . ':password@hobana.freenode.net/Bot_phpDaemon'
		];
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->client = \PHPDaemon\Clients\IRC\Pool::getInstance();
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
		$r   = $this->client->getConnection($this->config->url->value, function ($conn) use ($app) {
			$app->conn = $conn;
			if ($conn->connected) {
				\PHPDaemon\Core\Daemon::log('IRC bot connected at ' . $this->config->url->value);
				$conn->join('#botwar_phpdaemon');
				$conn->bind('motd', function ($conn) {
					//\PHPDaemon\Daemon::log($conn->motd);
				});
				$conn->bind('privateMsg', function ($conn, $msg) {
					\PHPDaemon\Core\Daemon::log('IRCBot: got private message \'' . $msg['body'] . '\' from \'' . $msg['from']['orig'] . '\'');
					$conn->message($msg['from']['nick'], 'You just wrote: ' . $msg['body']); // send the message back
				});
				$conn->bind('disconnect', function () use ($app) {
					$app->connect();
				});
			}
			else {
				\PHPDaemon\Core\Daemon::log('IRCBot: unable to connect (' . $this->config->url->value . ')');
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
			$this->client->onConfigUpdated();
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
