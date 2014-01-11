<?php
namespace PHPDaemon\Clients\Mongo;

use PHPDaemon\Clients\Mongo\Collection;
use PHPDaemon\Clients\Mongo\Connection;
use PHPDaemon\Clients\Mongo\ConnectionFinished;
use PHPDaemon\Network\Client;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * Class Pool
 * @package PHPDaemon\Clients\Mongo
 */
class Pool extends Client {
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var array */
	public $collections = []; // Objects of MongoClientAsyncCollection
	/** @var string */
	public $dbname = ''; // Current database
	/** @var */
	public $lastRequestConnection; // Holds last used MongoClientAsyncConnection object.

	/* Codes of operations */
	/**
	 * @TODO DESCR
	 */
	const OP_REPLY        = 1;
	/**
	 * @TODO DESCR
	 */
	const OP_MSG          = 1000;
	/**
	 * @TODO DESCR
	 */
	const OP_UPDATE       = 2001;
	/**
	 * @TODO DESCR
	 */
	const OP_INSERT       = 2002;
	/**
	 * @TODO DESCR
	 */
	const OP_QUERY        = 2004;
	/**
	 * @TODO DESCR
	 */
	const OP_GETMORE      = 2005;
	/**
	 * @TODO DESCR
	 */
	const OP_DELETE       = 2006;
	/**
	 * @TODO DESCR
	 */
	const OP_KILL_CURSORS = 2007;

	/** @var */
	public $cache; // object of MemcacheClient

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// default server list
			'servers'        => 'tcp://127.0.0.1',
			// default port
			'port'           => 27017,
			'maxconnperserv' => 32,
		];
	}

	/**
	 * Sets default database name
	 * @param string Database name
	 * @return boolean Success
	 */
	public function selectDB($name) {
		$this->dbname = $name;

		return true;
	}

	/**
	 * Generates auth. key
	 * @param string Username
	 * @param string Password
	 * @param string nonce
	 * @return string MD5 hash
	 */
	public static function getAuthKey($username, $password, $nonce) {
		return md5($nonce . $username . md5($username . ':mongo:' . $password));
	}

	/**
	 * Adds mongo server
	 * @param string  URL
	 * @param integer Weight
	 * @return void
	 */
	public function addServer($url, $weight = NULL, $mock = null) {
		$this->servers[$url] = $weight;
	}

	/**
	 * Gets the key
	 * @param integer Opcode (see constants above)
	 * @param string  Data
	 * @param boolean Is an answer expected?
	 * @param object  Connection. Optional.
	 * @param callable Sent callback
	 * @return void
	 * @throws ConnectionFinished
	 */
	public function request($opcode, $data, $reply = false, $conn = null, $sentcb = null) {
		$cb = $this->requestCbProducer($opcode, $data, $reply, $sentcb);
		if (is_object($conn) && ($conn instanceof Connection)) {
			if ($conn->isFinished()) {
				throw new ConnectionFinished;
			}
			$cb($conn);
		}
		else {
			$this->getConnectionRR($cb);
		}
	}

	/**
	 * @TODO DESCR
	 * @param $opcode
	 * @param $data
	 * @param bool $reply
	 * @param null $sentcb
	 * @return callable
	 */
	protected function requestCbProducer($opcode, $data, $reply = false, $sentcb = null) {
		return function ($conn) use ($opcode, $data, $reply, $sentcb) {
			if ($conn->isFinished()) {
				$this->getConnectionRR($this->requestCbProducer($opcode, $data, $reply, $sentcb));
				return;
			}
			$reqId = ++$conn->lastReqId;
			$this->lastRequestConnection = $conn;
			$conn->write(pack('VVVV', strlen($data) + 16, $reqId, 0, $opcode));
			$conn->write($data);
			if ($reply) {
				$conn->setFree(false);
			}
			if ($sentcb !== null) {
				call_user_func($sentcb, $conn, $reqId);
			}
		};
	}

	/**
	 * Finds objects in collection and fires callback when got all objects
	 * @param array Hash of properties (offset,  limit,  opts,  tailable,  await, where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function findAll($p, $cb) {
		$this->find($p, function($cursor) use ($cb) {
			if (!$cursor->isFinished()) {
				$cursor->getMore();
			} else {
				call_user_func($cb, $cursor);
			}
		});
	}

	/**
	 * Finds objects in collection
	 * @param array Hash of properties (offset,  limit,  opts,  tailable,  await, where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function find($p, $cb) {
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}

		if (!isset($p['limit'])) {
			$p['limit'] = 0;
		}

		if (!isset($p['opts'])) {
			$p['opts'] = '0';
		}

		if (isset($p['tailable']) && $p['tailable']) {
			$p['opts'] = '01000'.(isset($p['await']) && $p['await']?'1':'0').'00';
		}

		if (!isset($p['where'])) {
			$p['where'] = [];
		}

		$this->_params($p);

		$o = [];
		$s = false;

		foreach ($p as $k => $v) {
			if (
					($k === 'sort')
					|| ($k === 'hint')
					|| ($k === 'explain')
					|| ($k === 'snapshot')
			) {
				if (!$s) {
					$s = true;
				}

				if ($k === 'sort') {
					$o['orderby'] = $v;
				}
				elseif ($k === 'parse_oplog') {
				}
				elseif ($k == 'rp') {
					$o['$readPreference'] = $v;
				}
				else {
					$o[$k] = $v;
				}
			}
		}

		if ($s) {
			$o['query'] = $p['where'];
		}
		else {
			$o = $p['where'];
		}

		$bson = bson_encode($o);

		if (isset($p['parse_oplog'])) {
			$bson = str_replace("\x11\$gt", "\x09\$gt", $bson);
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY,
								chr(bindec(strrev($p['opts']))) . "\x00\x00\x00"
								. $p['col'] . "\x00"
								. pack('VV', $p['offset'], $p['limit'])
								. $bson
								. (isset($p['fields']) ? bson_encode($p['fields']) : '')
			, true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, false, isset($p['parse_oplog']), isset($p['tailable'])];
			});
	}

	/**
	 * Finds one object in collection
	 * @param array Hash of properties (offset,   opts,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function findOne($p, $cb) {
		if (isset($p['cachekey'])) {
			$db = $this;
			$this->cache->get($p['cachekey'], function ($r) use ($db, $p, $cb) {
				if ($r->result !== NULL) {
					call_user_func($cb, bson_decode($r->result));
				}
				else {
					unset($p['cachekey']);
					$db->findOne($p, $cb);
				}
			});

			return;
		}
		if (!isset($p['where'])) {
			$p['where'] = [];
		}

		$this->_params($p);

		$o = [];
		$s = false;

		foreach ($p as $k => $v) {
			if (
					($k === 'sort')
					|| ($k === 'hint')
					|| ($k === 'explain')
					|| ($k === 'snapshot')
			) {
				if (!$s) {
					$s = true;
				}

				if ($k === 'sort') {
					$o['orderby'] = $v;
				}
				elseif ($k === 'parse_oplog') {
				}
				elseif ($k == 'rp') {
					$o['$readPreference'] = $v;
				}
				else {
					$o[$k] = $v;
				}
			}
		}

		if ($s) {
			$o['query'] = $p['where'];
		}
		else {
			$o = $p['where'];
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY,
								pack('V', $p['opts'])
								. $p['col'] . "\x00"
								. pack('VV', $p['offset'], -1)
								. bson_encode($o)
								. (isset($p['fields']) ? bson_encode($p['fields']) : '')
			, true, null, function($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, true];
			});

	}

	/**
	 * Counts objects in collection
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function findCount($p, $cb) {
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}

		if (!isset($p['limit'])) {
			$p['limit'] = -1;
		}

		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		if (!isset($p['where'])) {
			$p['where'] = [];
		}

		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}

		$e = explode('.', $p['col'], 2);

		$query = [
			'count'  => $e[1],
			'query'  => $p['where'],
			'fields' => ['_id' => 1],
		];

		if (isset($p[$k = 'rp'])) {
			$v = $p[$k];
			if (is_string($v)) {
				$v = ['mode' => $v];
			}
			$query['$readPreference'] = $v;
		}

		if (is_string($p['where'])) {
			$query['where'] = new \MongoCode($p['where']);
		}
		elseif (
				is_object($p['where'])
				|| sizeof($p['where'])
		) {
			$query['query'] = $p['where'];
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, true];
		});
	}

	/**
	 * Sends authenciation packet
	 * @param array  Hash of properties (dbname,  user,  password,  nonce)
	 * @param mixed  Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function auth($p, $cb) {
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		$query = [
			'authenticate' => 1,
			'user'         => $p['user'],
			'nonce'        => $p['nonce'],
			'key'          => self::getAuthKey($p['user'], $p['password'], $p['nonce']),
		];

		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['dbname'], $cb, true];
		});
	}

	/**
	 * Sends request of nonce
	 * @return void
	 */
	public function getNonce($p, $cb) {
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		$query = [
			'getnonce' => 1,
		];
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['dbname'], $cb, true];
			});
	}

	/**
	 * @TODO DESCR
	 * @param array $keys
	 * @return string
	 */
	public function getIndexName($keys) {
		$name  = '';
		$first = true;
		foreach ($keys as $k => $v) {
			$name .= ($first ? '_' : '') . $k . '_' . $v;
			$first = false;
		}
		return $name;
	}

	/**
	 * Ensure index
	 * @param string Collection
	 * @param array  Keys
	 * @param array  Optional. Options
	 * @param mixed  Optional. Callback called when response received
	 * @return void
	 */
	public function ensureIndex($ns, $keys, $options = [], $cb = null) {
		$e   = explode('.', $ns, 2);
		$doc = [
			'ns'   => $ns,
			'key'  => $keys,
			'name' => isset($options['name']) ? $options['name'] : $this->getIndexName($keys),
		];
		if (isset($options['unique'])) {
			$doc['unique'] = $options['unique'];
		}
		if (isset($options['sparse'])) {
			$doc['sparse'] = $options['sparse'];
		}
		if (isset($options['version'])) {
			$doc['v'] = $options['version'];
		}
		if (isset($options['background'])) {
			$doc['background'] = $options['background'];
		}
		if (isset($options['ttl'])) {
			$doc['expireAfterSeconds'] = $options['ttl'];
		}
		$this->getCollection($e[0] . '.system.indexes')->insert($doc, $cb);
	}

	/**
	 * Gets last error
	 * @param string Dbname
	 * @param mixed  Callback called when response received
	 * @param array  Parameters.
	 * @param object Connection. Optional.
	 * @return void
	 */
	public function lastError($db, $cb, $params = [], $conn = null) {
		$e                      = explode('.', $db, 2);
		$params['getlasterror'] = 1;
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY,
			pack('V', 0)
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($params)
			, true, $conn, function ($conn, $reqId = null) use ($db, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$db, $cb, true];
		});
	}

	/**
	 * Find objects in collection using min/max specifiers
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col,  min,  max)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function range($p, $cb) {
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}

		if (!isset($p['limit'])) {
			$p['limit'] = -1;
		}

		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		if (!isset($p['where'])) {
			$p['where'] = [];
		}

		if (!isset($p['min'])) {
			$p['min'] = [];
		}

		if (!isset($p['max'])) {
			$p['max'] = [];
		}

		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}

		$e = explode('.', $p['col'], 2);

		$query = [
			'$query' => $p['where'],
		];

		if (sizeof($p['min'])) {
			$query['$min'] = $p['min'];
		}

		if (sizeof($p['max'])) {
			$query['$max'] = $p['max'];
		}

		if (is_string($p['where'])) {
			$query['where'] = new \MongoCode($p['where']);
		}
		elseif (
				is_object($p['where'])
				|| sizeof($p['where'])
		) {
			$query['query'] = $p['where'];
		}

		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, true];
		});
	}

	/**
	 * Evaluates a code on the server side
	 * @param string Code
	 * @param mixed  Callback called when response received
	 * @return void
	 */
	public function evaluate($code, $cb) {
		$p = [];

		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}

		if (!isset($p['limit'])) {
			$p['limit'] = -1;
		}

		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		if (!isset($p['db'])) {
			$p['db'] = $this->dbname;
		}

		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $p['db'] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode(['$eval' => new \MongoCode($code)])
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['db'], $cb, true];
			});
	}

	/**
	 * Returns distinct values of the property
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col, where)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function distinct($p, $cb) {
		$this->_params($p);
		$e = explode('.', $p['col'], 2);

		$query = [
			'distinct' => $e[1],
			'key'      => $p['key'],
		];

		if (isset($p[$k = 'rp'])) {
			$v = $p[$k];
			if (is_string($v)) {
				$v = ['mode' => $v];
			}
			$query['$readPreference'] = $v;
		}

		if (isset($p['where'])) {
			$query['query'] = $p['where'];
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, true];
		});
	}

	protected function _paramFields($f) {
		if (is_string($f)) {
			$f = array_map('trim', explode(',', $f));
		}
		if (!is_array($f) || sizeof($f) == 0) {
			return null;
		}
		if (!isset($f[0])) {
			return $f;
		}
		$p = [];
		foreach ($f as $k) {
			$p[$k] = 1;
		}
		return $p;
	}

	protected function _params(&$p) {
		foreach ($p as $k => &$v) {
			if ($k === 'fields' || $k === 'sort') {
				$v = $this->_paramFields($v);
			} elseif ($k === 'where') {
				if (is_string($v)) {
					$v = new \MongoCode($v);
				}
			}
			elseif ($k === 'reduce') {
				if (is_string($v)) {
					$v = new \MongoCode($v);
				}
			}
			elseif ($k === 'rp') {
				if (is_string($v)) {
					$v = ['mode' => $v];
				}
			}
		}

		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}

		if (!isset($p['limit'])) {
			$p['limit'] = -1;
		}

		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}

		if (!isset($p['key'])) {
			$p['key'] = '';
		}

		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}
	}

	/**
	 * Find and modify
	 * @param array Hash of properties
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function findAndModify($p, $cb) {
		$this->_params($p);
		$e = explode('.', $p['col'], 2);
		$query = [
			'findAndModify' => $e[1],
		];

		if (isset($p[$k = 'rp'])) {
			$v = $p[$k];
			if (is_string($v)) {
				$v = ['mode' => $v];
			}
			$query['$readPreference'] = $v;
		}

		if (isset($p['sort'])) {
			$query['sort'] = $p['sort'];
		}
		if (isset($p['update'])) {
			$query['update'] = $p['update'];
		}
		if (isset($p['new'])) {
			$query['new'] = (boolean) $p['new'];
		}
		if (isset($p['remove'])) {
			$query['remove'] = (boolean) $p['remove'];
		}
		if (isset($p['upsert'])) {
			$query['upsert'] = (boolean) $p['upsert'];
		}
		if (isset($p['where'])) {
			$query['query'] = $p['where'];
		}
		elseif (isset($p['query'])) {
			$query['query'] = $p['query'];
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, true];
		});
	}

	/**
	 * Groupping function
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col,  reduce,  initial)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function group($p, $cb) {
		if (!isset($p['reduce'])) {
			$p['reduce'] = '';
		}
		$this->_params($p);

		$e = explode('.', $p['col'], 2);

		$query = [
			'group' => [
				'ns'      => $e[1],
				'key'     => $p['key'],
				'$reduce' => $p['reduce'],
				'initial' => $p['initial'],
			]
		];

		if (isset($p[$k = 'cond'])) {
			$query['group'][$k] = $p[$k];
		}

		if (isset($p['rp'])) {
			$query['$readPreference'] = $p['rp'];
		}

		if (isset($p[$k = 'finalize'])) {
			if (is_string($p[$k])) {
				$p[$k] = new \MongoCode($p[$k]);
			}

			$query['group'][$k] = $p[$k];
		}

		if (isset($p[$k = 'keyf'])) {
			$query[$k] = $p[$k];
		}


		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, false];
			});
	}

	/**
	 * Aggregate function
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col)
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function aggregate($p, $cb) {
		$this->_params($p);

		$e = explode('.', $p['col'], 2);
		$query = [
			'aggregate' => $e[1]
		];

		if (isset($p['rp'])) {
			$query['$readPreference'] = $p['rp'];
			unset($p['rp']);
		}
		foreach ($p as $k => $v) {
			if (substr($k, 0, 1) === '$' || $k === 'pipeline') {
				$query[$k] = $v;
			}
		}
		$cb = CallbackWrapper::wrap($cb);
		$this->request(self::OP_QUERY, pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : ''), true, null, function ($conn, $reqId = null) use ($p, $cb) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$p['col'], $cb, false];
			});
	}

	/**
	 * Updates one object in collection
	 * @param string   Collection's name
	 * @param array    Conditions
	 * @param array    Data
	 * @param integer  Optional. Flags.
	 * @param callback Callback (getLastError)
	 * @param array    Parameters (getLastError).
	 * @return void
	 */
	public function update($col, $cond, $data, $flags = 0, $cb = NULL, $params = []) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		if (is_string($cond)) {
			$cond = new \MongoCode($cond);
		}

		if ($flags) {
			//if (!isset($data['_id'])) {$data['_id'] = new MongoId();}
		}

		$this->request(self::OP_UPDATE,
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. pack('V', $flags)
			. bson_encode($cond)
			. bson_encode($data)
		, false, null, function ($conn, $reqId = null) use ($cb, $col, $params) {
			if (!$conn) {
				return;
			}
			if ($cb !== NULL) {
				$this->lastError($col, $cb, $params, $conn);
			}
		});
	}

	/**
	 * Updates one object in collection
	 * @param string   Collection's name
	 * @param array    Conditions
	 * @param array    Data
	 * @param callback Callback (getLastError)
	 * @param array    Parameters (getLastError).
	 * @return void
	 */
	public function updateOne($col, $cond, $data, $cb = NULL, $params = []) {
		$this->update($col, $cond, $data, 0, $cb, $params);	
	}

	/**
	 * Updates several objects in collection
	 * @param string   Collection's name
	 * @param array    Conditions
	 * @param array    Data
	 * @param callback Callback
	 * @param array    Parameters (getLastError).
	 * @return void
	 */
	public function updateMulti($col, $cond, $data, $cb = NULL, $params = []) {
		$this->update($col, $cond, $data, 2, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param string  Collection's name
	 * @param array   Conditions
	 * @param array   Data
	 * @param array	  Parameters.
	 * @return void
	 */
	public function upsert($col, $cond, $data, $multi = false, $cb = NULL, $params = []) {
		$this->update($col, $cond, $data, $multi ? 3 : 1, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param string  Collection's name
	 * @param array   Conditions
	 * @param array   Data
	 * @param array	  Parameters.
	 * @return void
	 */
	public function upsertOne($col, $cond, $data, $cb = NULL, $params = []) {
		$this->update($col, $cond, $data, 1, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param string  Collection's name
	 * @param array   Conditions
	 * @param array   Data
	 * @param array	  Parameters.
	 * @return void
	 */
	public function upsertMulti($col, $cond, $data, $cb = NULL, $params = []) {
		$this->update($col, $cond, $data, 3, $cb, $params);
	}

	/**
	 * Inserts an object
	 * @param string   Collection's name
	 * @param array    Data
	 * @param callback Callback (getLastError)
	 * @param array    Parameters (getLastError).
	 * @return MongoId
	 */
	public function insert($col, $doc = [], $cb = NULL, $params = []) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		if (!isset($doc['_id'])) {
			$doc['_id'] = new \MongoId();
		}

		$this->request(self::OP_INSERT,
								"\x00\x00\x00\x00"
								. $col . "\x00"
								. bson_encode($doc)
		, false, null, function ($conn, $reqId = null) use ($cb, $col, $params) {
			if ($cb !== NULL) {
				$this->lastError($col, $cb, $params, $conn);
			}
		});

		return $doc['_id'];
	}

	/**
	 * Sends a request to kill certain cursors on the server side
	 * @param array Array of cursors
	 * @param object Connection
	 * @return void
	 */
	public function killCursors($cursors = [], $conn) {
		$this->request(self::OP_KILL_CURSORS,
					   "\x00\x00\x00\x00"
					   . pack('V', sizeof($cursors))
					   . implode('', $cursors)
		, false, $conn);
	}

	/**
	 * Inserts several documents
	 * @param string Collection's name
	 * @param array  Array of docs
	 * @return array IDs
	 */
	public function insertMulti($col, $docs = [], $cb = NULL, $params = []) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		$ids  = [];
		$bson = '';

		foreach ($docs as &$doc) {
			if (!isset($doc['_id'])) {
				$doc['_id'] = new \MongoId();
			}

			$bson .= bson_encode($doc);

			$ids[] = $doc['_id'];
		}

		$this->request(self::OP_INSERT,
					   "\x00\x00\x00\x00"
					   . $col . "\x00"
					   . $bson
		, false, null, function ($conn, $reqId = null) use ($cb, $col, $params) {
			if ($cb !== NULL) {
				$this->lastError($col, $cb, $params, $conn);
			}
		});
		return $ids;
	}

	/**
	 * Remove objects from collection
	 * @param string Collection's name
	 * @param array  Conditions
	 * @param mixed  Optional. Callback called when response received.
	 * @return void
	 */
	public function remove($col, $cond = [], $cb = NULL, $params = []) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		if (is_string($cond)) {
			$cond = new \MongoCode($cond);
		}

		$this->request(self::OP_DELETE,
					   "\x00\x00\x00\x00"
					   . $col . "\x00"
					   . "\x00\x00\x00\x00"
					   . bson_encode($cond)
		, false, null, function ($conn, $reqId = null) use ($col, $cb, $params) {
			if (!$conn) {
				return;
			}
			if ($cb !== NULL) {
				$this->lastError($col, $cb, $params, $conn);
			}
		});
	}

	/**
	 * Asks for more objects
	 * @param string  Collection's name
	 * @param string  Cursor's ID
	 * @param integer Number of objects
	 * @param object Connection
	 * @return void
	 */
	public function getMore($col, $id, $number, $conn) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		$this->request(self::OP_GETMORE,
			 "\x00\x00\x00\x00"
			. $col . "\x00"
			. pack('V', $number)
			. $id, false, $conn, function ($conn, $reqId = null) use ($id) {
				if (!$conn) {
					return;
				}
				$conn->requests[$reqId] = [$id];
			}
		);
	}

	/**
	 * Returns an object of collection
	 * @param string Collection's name
	 * @return Collection
	 */
	public function getCollection($col) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		else {
			$collName = explode('.', $col, 2);
		}

		if (isset($this->collections[$col])) {
			return $this->collections[$col];
		}

		return $this->collections[$col] = new Collection($col, $this);
	}

	/**
	 * Magic getter-method. Proxy for getCollection.
	 * @param string Collection's name
	 * @return Collection
	 */
	public function __get($name) {
		return $this->getCollection($name);
	}
}
