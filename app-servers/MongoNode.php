<?php

/**
 * @package Applications
 * @subpackage MongoNode
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MongoNode extends AppInstance {
	
	public $db; // MongoClient
	public $cache; // MemcacheClient
	public $RTEPClient; // RTEPClient
	public $LockClient; // LockClient
	public $cursor; // Tailable cursor

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// disabled by default
			'enable'     => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			$this->LockClient = Daemon::$appResolver->getInstanceByAppName('LockClient');
			$this->db = Daemon::$appResolver->getInstanceByAppName('MongoClient');
			$this->cache = Daemon::$appResolver->getInstanceByAppName('MemcacheClient');
			$this->RTEPClient = Daemon::$appResolver->getInstanceByAppName('RTEPClient');
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->config->enable->value) {
			$appInstance = $this;
			
			$this->LockClient->job(__CLASS__,TRUE,
				function($command,$jobname,$client) use ($appInstance) {
					$appInstance->pushRequest(new MongoNode_ReplicationRequest($appInstance, $appInstance));
				}
			);
		}
	}

	/**
	 * Method called when object received.
	 * @param object Object.
	 * @return void
	 */
	public function cacheObject($o) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '(' . json_encode($o) . ')');
		}

		if (isset($o['_key'])) {
			$this->cache->set($o['_key'], bson_encode($o));
			$this->cache->set('_id.' . ((string)$o['_id']), $o['_key']);
		}

		if (isset($o['_ev'])) {
			$o['name'] = $o['_ev'];
			
			if (Daemon::$config->logevents->value) {
				Daemon::log('MongoNode send event ' . $o['name']);
			}

			$this->RTEPClient->client->request(array(
				'op'    => 'event',
				'event' => $o,
			));
		}
	}

	/**
	 * Method called when object deleted.
	 * @param object Object.
	 * @return void
	 */
	public function deleteObject($o) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '(' . json_encode($o) . ')');
		}
	      
		$this->cache->get('_id.' . ((string)$o['_id']),
			function($m) use ($o) {
				if (is_string($m->result)) {
					$m->appInstance->delete($m->result);
					$m->appInstance->delete('_id.' . $o['_id']);
				}
			}
		);
	}

	/**
	 * Initializes slave session.
	 * @param object Object.
	 * @return void
	 */
	public function initSlave($point) {
		$node = $this;
	      
		$this->db->{'local.oplog.$main'}->find(
			function($cursor) use ($node) {
				$node->cursor = $cursor;
				$cursor->state = 1;
				$cursor->lastOpId = NULL;
			     
				foreach ($cursor->items as $k => &$item) {
					if (Daemon::$config->logevents->value) {
						Daemon::log(get_class($node) . ': caught oplog-record with ts = (' . Debug::dump($item['ts']) . ')');
					}
				    
					$cursor->lastOpId = $item['ts'];
				    
					if ($item['op'] == 'i') {
						$node->cacheObject($item['o']);
					}
					elseif ($item['op'] == 'd') {
						$node->deleteObject($item['o']);
					}
					elseif ($item['op'] == 'u') {
						if (
							isset($item['b']) 
							&& ($item['b'] === FALSE)
						) {
							$item['o']['_id'] = $item['o2']['_id'];
							$node->cacheObject($item['o']);
						} else {
							$cursor->appInstance->{$item['ns']}->findOne(
								function($item) use ($node) {
									$node->cacheObject($item);
								},
								array('where' => array('_id' => $item['o2']['_id']))
							);
						}
					}
				    
					unset($cursor->items[$k]);
				}
			}, array(
				'tailable' => TRUE,
				'sort' => array('$natural' => 1),
				'snapshot' => 1,
				'where' => array(
					'ts' => array(
						'$gt' => $point
					),
					'$exists' => array(
						'_key' => TRUE
					)
				),
				'parse_oplog' => TRUE,
			)
		);
	}
	
}

class MongoNode_ReplicationRequest extends Request {
       
	public $inited = FALSE; // Initialized?

	/**
	 * Called when request iterated.
	 * @return void
	 */
	public function run() {
		if (!$this->appInstance->cursor) {
			if (!$this->inited) {
				$req = $this;
				$this->inited = TRUE;
			    
				$this->appInstance->cache->get('_rp',
					function ($answer) use ($req) {
						$req->inited = FALSE;
						$e = explode(' ', $answer->result);
					   
						if (isset($e[1])) {
							$ts = new MongoTimestamp((int) $e[0], (int) $e[1]);
						} else {
							$ts = new MongoTimestamp(0, 0);
						}
					   
						if (Daemon::$config->logevents->value) {
							Daemon::log('MongoNode: replication point - ' . $answer->result . ' (' . Debug::dump($ts) . ')');
						}
					   
						$req->appInstance->initSlave($ts);
					}
				);
			}
		}
		elseif (!$this->appInstance->cursor->session->busy) {
			if ($this->appInstance->cursor->lastOpId !== NULL) {
				$this->appInstance->cache->set('_rp', $this->appInstance->cursor->lastOpId);
				$this->appInstance->cursor->lastOpId = NULL;
			}
		     
			try {
				$this->appInstance->cursor->getMore();
			} catch (MongoClientSessionFinished $e) {
				$this->appInstance->cursor = FALSE;
			}
		}
		
		$this->sleep(0.3);
	}
	
}
