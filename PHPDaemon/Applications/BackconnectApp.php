<?php
/**
 * BackconnectApp application instance
 *
 * @package Core
 *
 */
class BackconnectApp extends AppInstance {
	public $pool;
	public $clientPools = array();
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'listen' => '0.0.0.0:2000,0.0.0.0:2001',
			'porttimeout' => 60,	   // Таймаут после которого закрывается клиентский порт, если нет ни однго соединения от бота  
			'timeout' => 120,	   //  Таймаут после которого сбрасываются не активные соединения
			'timer' => 5,		   // Таймаут сброса данных в mongo
			'maxworkingclients' => 20, // Максимальное количество обслуживаемых клиентских соединений, остальные ставятся в очередь
		);
	}
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->botPool = BackconnectBotPool::getInstance(array(
					'listen' => $this->config->listen->value,
					'maxboundsockets' => 1
			));
			$this->botPool->appInstance = $this;
			$this->mongo = MongoClient::getInstance();
			$this->mongoBots = $this->mongo->{'controller.bc'};
			$app = $this;
			$this->timer = setTimeout(function($timer) use ($app) {
				foreach ($app->clientPools as $id => $pool) {
					if (
						($pool->getNumberOfWorkingClients() === 0)
						&& ($pool->getNumberOfSpareBots() === 0)
						&& ($pool->lastBotFinishTime < time() - $app->config->porttimeout->value)
					) {
						$pool->finish();
						continue;
					}
					$pool->pushStats();
				}
				$timer->timeout();
			}, 1e6 * $this->config->timer->value);
		}
	}

	public function getClientPool($id) {
		if (!isset($this->clientPools[$id])) {
			$pool = BackconnectClientPool::getInstance(array('listen' => '0.0.0.0:0'));
			$pool->boundPort = $pool->bound->getFirst()->port;
			//Daemon::log('boundPort: '.$pool->boundPort);
			$pool->appInstance = $this;
			$pool->clientPoolId = $id;
			$pool->onReady();
			return $this->clientPools[$id] = $pool;
		}
		return $this->clientPools[$id];

	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->botPool) {
			$this->botPool->onReady();
		}
	}
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($this->botPool) {
			return $this->botPool->onConfigUpdated();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		if ($this->botPool) {
			$this->botPool->onShutdown();
		}
		foreach ($this->clientPools as $pool) {
			$pool->onShutdown();
		}
		if ($this->timer) {
			clearTimeout($this->timer);
			$this->timer = null;
		}
		return true;
	}
}


class BackconnectBotPool extends NetworkServer {}

class BackconnectBotPoolConnection extends Connection { // bots
	const STATE_SPARE = 1;
	const STATE_LINKED = 2;
	protected $highMark = 1024; // default highmark
	protected $clientpool;
	public $linkedClientConn;

	public function __construct($fd = null, $pool = null) {
			$this->timeout = $pool->appInstance->config->timeout->value;
			parent::__construct($fd, $pool);
	}

	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		if ($this->linkedClientConn) {
			$this->linkedClientConn->pool->lastBotFinishTime = time();
		}
		//Daemon::log(get_class($this).'::onFinish()');
		parent::onFinish();
		unset($this->onWrite);
		if ($this->linkedClientConn) {
			$this->linkedClientConn->finish();
			unset($this->linkedClientConn);
		}
		if ($this->clientpool) {
			$this->clientpool->removeSpareBot($this);
		}
	}

	public function linkWithClient($client) {
		$this->linkedClientConn = $client;
		$this->state = self::STATE_LINKED;
		$this->setWatermark(1, 0xFFFF);
		$this->unlockRead();
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		//Daemon::log(get_class($this).' ('.$this->id.'): '.Debug::exportBytes($buf));
		start:
		if ($this->state === self::STATE_LINKED) {
			if ($this->linkedClientConn) {
				$this->linkedClientConn->write($buf);
			}
		}
		elseif ($this->state === self::STATE_SPARE) {
			if ($buf !== '') {
				Daemon::log(get_class($this).': unexpected data in unlinked bot-connection : '.Debug::exportBytes($buf));
			}
		} else {
			$this->buf .= $buf;
			$buf = '';
			if (strlen($this->buf) > 1024) { // size-limit of the handshake packet exceed
				$this->finish();
				return;
			}
			if (($l = $this->gets()) !== false) {
				if ($this->buf !== '') {
					Daemon::log(get_class($this).': unexpected data after handshake packet: '.Debug::exportBytes($this->buf));
					$this->buf = '';
				}

				$this->clientPoolId = trim($l);
				$this->clientpool = $this->pool->appInstance->getClientPool($this->clientPoolId);
				if (!$this->clientpool) { // aborting connection
					$this->finish();
					return;
				}
				if (
					($this->clientpool->getNumberOfWorkingClients() < $this->clientpool->appInstance->config->maxworkingclients->value)
					&& ($client = $this->clientpool->getWaitingClient())
				) { // we have a waiting client
					$this->linkWithClient($client);
					$client->linkWithBot($this);
				} else { // adding to the spare bots list
					$this->state = self::STATE_SPARE;
					$this->lockRead();
					$this->clientpool->addSpareBot($this);
				}
			}
		}
	}
	
}

class BackconnectClientPool extends NetworkServer {
	protected $spareBots;
	protected $waitingClients;
	public $clientPoolId;
	public $lastBotFinishTime;
	public $workingClients = 0;
	public function init() {
		$this->spareBots = new ObjectStorage;
		$this->waitingClients = new ObjectStorage;
	}
	public function getNumberOfWorkingClients() {
		return (int) $this->workingClients;
	}
	public function getNumberOfWaitingClients() {
		return (int) $this->waitingClients->count();
	}
	public function getNumberOfSpareBots() {
		return (int) $this->spareBots->count();
	}
	public function getWaitingClient() {
		return $this->waitingClients->detachFirst();
	}
	public function addWaitingClient($client) {
		$this->waitingClients->attach($client);
	}
	public function getSpareBot() {
		return $this->spareBots->detachFirst();
	}
	public function addSpareBot($bot) {
		$this->spareBots->attach($bot);
	}
	public function removeSpareBot($bot) {
		$this->spareBots->detach($bot);
	}
	public function removeWaitingClient($client) {
		$this->waitingClients->detach($client);
	}
	public function pushStats() {
		$this->mongoId = $this->appInstance->mongoBots->upsert(array(
				'id' => $this->clientPoolId,
			),	array(
				'id' => $this->clientPoolId,
				'boundPort' => $this->boundPort,
				'spareBots' => $this->getNumberOfSpareBots(),
				'workingClients' => $this->getNumberOfWorkingClients(),
				'waitingClients' => $this->getNumberOfWaitingClients(),
				'mtime' => microtime(true),
				'timestamp' => time()
		));
	}
	public function onReady() {
		$this->pushStats();
		$this->enable();
	}
	public function onFinish() {
		unset($this->appInstance->clientPools[$this->clientPoolId]);
		$this->appInstance->mongoBots->remove(array('_id' => $this->mongoId));
	}
	public function onShutdown() {
		parent::onShutdown();
	}
	public function checkQueue() {
		start:
		while (
			($this->getNumberOfSpareBots() > 0) && ($this->getNumberOfWaitingClients() > 0)
			&& ($this->getNumberOfWorkingClients() <= $this->appInstance->config->maxworkingclients->value)
		) {
			$bot = $this->getSpareBot();
			$client = $this->getWaitingClient();
			$bot->linkWithClient($client);
			$client->linkWithBot($bot);
		}
	}
}

class BackconnectClientPoolConnection extends Connection { // browser
	const STATE_QUEUED = 1;
	const STATE_LINKED = 2;
	protected $highMark = 0xFFFF; // default highmark
	public $linkedBotConn;

	public function onReady() {
		if (
			($this->pool->getNumberOfWorkingClients() <= $this->pool->appInstance->config->maxworkingclients->value)
			&& ($bot = $this->pool->getSpareBot())) { // we have a spare bot
			//Daemon::log(get_class($this).': init(): we have a spare bot');
			$this->linkWithBot($bot);
			$bot->linkWithClient($this);
		} else { // adding to the queue
			//Daemon::log(get_class($this).': init(): adding to the queue');
			$this->pool->addWaitingClient($this);
			$this->lockRead();
		}
	}

	public function linkWithBot($bot) {
		$this->linkedBotConn = $bot;
		$conn = $this;
		$this->onWrite = function() use ($conn) {
			$conn->unlockRead();
		};
		$this->state = self::STATE_LINKED;
		$this->setWatermark(1, 0xFFFF);
		$this->unlockRead();
		++$this->pool->workingClients;
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($this->linkedBotConn) {
			$this->lockRead();
			//Daemon::log(get_class($this).': ('.$this->linkedBotConn->id.'): '.Debug::exportBytes($buf));
			$this->linkedBotConn->write($buf);
		} else {
			Daemon::log(get_class($this).': unexpected data: '.Debug::exportBytes($buf));
		}
	}
	
	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		//Daemon::log(get_class($this).'::onFinish()');
		parent::onFinish();
		unset($this->onWrite);
		if ($this->linkedBotConn) {
			--$this->pool->workingClients;
			$this->linkedBotConn->finish();
			unset($this->linkedBotConn);
			$this->pool->checkQueue();
		}
		$this->pool->removeWaitingClient($this);

	}
}
