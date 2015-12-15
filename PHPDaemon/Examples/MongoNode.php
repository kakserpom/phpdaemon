<?php
namespace PHPDaemon\Examples;

/**
 * @package    Applications
 * @subpackage MongoNode
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class MongoNode extends \PHPDaemon\Core\AppInstance {

	public $db; // MongoClient
	public $cache; // MemcacheClient
	public $LockClient; // LockClient
	public $cursor; // Tailable cursor
	public $timer;
	protected $inited = false;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'mongoclientname'    => '',
			'memcacheclientname' => '',
		];
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->db    = \PHPDaemon\Clients\Mongo\Pool::getInstance($this->config->mongoclientname->value);
		$this->cache = \PHPDaemon\Clients\Memcache\Pool::getInstance($this->config->memcacheclientname->value);
		if (!isset($this->config->limitinstances)) {
			$this->log('missing \'limitInstances\' directive');
		}
	}

	public function touchCursor() {
		if (!$this->cursor) {
			if (!$this->inited) {
				$this->inited = true;

				$this->cache->get('_rp',
					function ($answer) {
						$this->inited = false;
						$e            = explode(' ', $answer->result);

						if (isset($e[1])) {
							$ts = new \MongoTimestamp((int)$e[0], (int)$e[1]);
						}
						else {
							$ts = new \MongoTimestamp(0, 0);
						}

						if (\PHPDaemon\Core\Daemon::$config->logevents->value) {
							\PHPDaemon\Core\Daemon::log('MongoNode: replication point - ' . $answer->result . ' (' . \PHPDaemon\Core\Debug::dump($ts) . ')');
						}

						$this->initSlave($ts);
					}
				);
			}
		}
		elseif (!$this->cursor->session->busy) {
			if ($this->cursor->lastOpId !== NULL) {
				$this->cache->set('_rp', $this->cursor->lastOpId);
				$this->cursor->lastOpId = NULL;
			}

			try {
				$this->cursor->getMore();
			} catch (\PHPDaemon\Clients\Mongo\ConnectionFinished $e) {
				$this->cursor = FALSE;
			}
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->config->enable->value) {
			$this->timer = setTimeout(function ($timer) {
				$this->touchCursor();
			}, 0.3e6);
		}
	}

	/**
	 * Method called when object received.
	 * @param object Object.
	 * @return void
	 */
	public function cacheObject($o) {
		if (\PHPDaemon\Core\Daemon::$config->logevents->value) {
			\PHPDaemon\Core\Daemon::log(__METHOD__ . '(' . json_encode($o) . ')');
		}

		if (isset($o['_key'])) {
			$this->cache->set($o['_key'], bson_encode($o));
			$this->cache->set('_id.' . ((string)$o['_id']), $o['_key']);
		}

		if (isset($o['_ev'])) {
			$o['name'] = $o['_ev'];

			if (\PHPDaemon\Core\Daemon::$config->logevents->value) {
				\PHPDaemon\Core\Daemon::log('MongoNode send event ' . $o['name']);
			}
		}
	}

	/**
	 * Method called when object deleted.
	 * @param object Object.
	 * @return void
	 */
	public function deleteObject($o) {
		if (\PHPDaemon\Core\Daemon::$config->logevents->value) {
			\PHPDaemon\Core\Daemon::log(__METHOD__ . '(' . json_encode($o) . ')');
		}

		$this->cache->get('_id.' . ((string)$o['_id']),
			function ($mc) use ($o) {
				if (is_string($m->result)) {
					$mc->delete($m->result);
					$mc->delete('_id.' . $o['_id']);
				}
			}
		);
	}

	/**
	 * Initializes slave session.
	 * @param object Object.
	 * @param \MongoTimestamp $point
	 * @return void
	 */
	public function initSlave($point) {
		$this->db->{'local.oplog.$main'}->find(

			/**
			 * @param \MongoTimestamp $cursor
			 */
			function ($cursor) {
				$this->cursor     = $cursor;
				$cursor->state    = 1;
				$cursor->lastOpId = NULL;

				foreach ($cursor->items as $k => &$item) {
					if (\PHPDaemon\Core\Daemon::$config->logevents->value) {
						\PHPDaemon\Core\Daemon::log(get_class($this) . ': caught oplog-record with ts = (' . \PHPDaemon\Core\Debug::dump($item['ts']) . ')');
					}

					$cursor->lastOpId = $item['ts'];

					if ($item['op'] === 'i') {
						$this->cacheObject($item['o']);
					}
					elseif ($item['op'] === 'd') {
						$this->deleteObject($item['o']);
					}
					elseif ($item['op'] === 'u') {
						if (
								isset($item['b'])
								&& ($item['b'] === FALSE)
						) {
							$item['o']['_id'] = $item['o2']['_id'];
							$this->cacheObject($item['o']);
						}
						else {
							$cursor->appInstance->{$item['ns']}->findOne(

								/**
								 * @param \MongoTimestamp $item
								 */
								function ($item) {
									$this->cacheObject($item);
								},
								['where' => ['_id' => $item['o2']['_id']]]
							);
						}
					}

					unset($cursor->items[$k]);
				}
			}, [
				   'tailable'    => TRUE,
				   'sort'        => ['$natural' => 1],
				   'snapshot'    => 1,
				   'where'       => [
					   'ts'      => [
						   '$gt' => $point
					   ],
					   '$exists' => [
						   '_key' => TRUE
					   ]
				   ],
				   'parse_oplog' => TRUE,
			   ]
		);
	}
}
