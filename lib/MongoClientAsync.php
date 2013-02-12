<?php
/**
 * @package Applications
 * @subpackage MongoClientAsync
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MongoClientAsync extends NetworkClient {
	public $noSAF = true;
	
	public $requests    = array(); // Pending requests
	public $cursors     = array(); // Active cursors
	public $lastReqId   = 0;       // ID of the last request
	public $collections = array(); // Objects of MongoClientAsyncCollection
	public $dbname      = '';      // Current database
	public $lastRequestConnection;    // Holds last used MongoClientAsyncConnection object.

	/* Codes of operations */
	const OP_REPLY        = 1; 
	const OP_MSG          = 1000;
	const OP_UPDATE       = 2001;
	const OP_INSERT       = 2002;
	const OP_QUERY        = 2004;
	const OP_GETMORE      = 2005;
	const OP_DELETE       = 2006;
	const OP_KILL_CURSORS = 2007;

	public $cache;                 // object of MemcacheClient

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// default server list
			'servers' => 'mongo://127.0.0.1',
			// default port
			'port'    => 27017,
			'maxconnperserv' => 32,
		);
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
	 * @param string URL
	 * @param integer Weight
	 * @return void
	 */
	public function addServer($url, $weight = NULL, $mock = null) {
		$this->servers[$url] = $weight;
	}

	/**
	 * Gets the key
	 * @param string Key
	 * @param integer Opcode (see constants above)
	 * @param string Data
	 * @param boolean Is an answer expected?
	 * @return integer Request ID
	 * @throws MongoClientAsyncConnectionFinished
	 */
	public function request($key, $opcode, $data, $reply = false) {
		$reqId = ++$this->lastReqId;
		$cb = function ($conn) use ($opcode, $data, $reply, $reqId) {
			if (!$conn->connected) {
				throw new MongoClientConnectionAsyncFinished;
			}
			$conn->pool->lastRequestConnection = $conn;
			$conn->write(pack('VVVV', strlen($data) + 16, $reqId, 0, $opcode) . $data);
			if ($reply) {
				$conn->setFree(false);
			}
		};
		if (
			(is_object($key) 
			&& ($key instanceof MongoClientAsyncConnection))
		) {
			$cb($key);
		} else {
			$this->getConnectionByKey($key, $cb);
		}
		return $reqId;
	}

	/**
	 * Finds objects in collection
	 * @param array Hash of properties (offset,  limit,  opts,  tailable,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function find($p, $callback, $key = '') {
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}
		
		if (!isset($p['limit'])) {
			$p['limit'] = 0;
		}
		
		if (!isset($p['opts'])) {
			$p['opts'] = '0';
		}
		
		if (isset($p['tailable'])) {
			$p['opts'] = '01000100';
		}
		
		if (isset($p['tailable'])) {
			// comment this to use AwaitData
			$p['opts'] = '01000000';
		} 
		
		if (!isset($p['where'])) {
			$p['where'] = array();
		}
		
		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}
	
		if (
			isset($p['fields']) 
			&& is_string($p['fields'])
		) {
			$e = explode(',', $p['fields']);
			$p['fields'] = array();

			foreach ($e as &$f) {
				$p['fields'][$f] = 1;
			}
		}

		if (is_string($p['where'])) {
			$p['where'] = new MongoCode($p['where']);
		}
	
		$o = array();
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
				elseif ($k === 'parse_oplog') {}
				elseif ($k == 'rp') {
					if (is_string($v)) {
						$v = ['mode' => $v];
					}
					$o['$readPreference'] = $v;
				}
				else {
					$o[$k] = $v;
				}
			}
		}
		
		if ($s) {
			$o['query'] = $p['where'];
		} else {
			$o = $p['where'];
		}

		$bson = bson_encode($o);

		if (isset($p['parse_oplog'])) {
			$bson = str_replace("\x11\$gt", "\x09\$gt", $bson);
		}

		$reqId = $this->request($key, self::OP_QUERY, 
			chr(bindec(strrev($p['opts']))) . "\x00\x00\x00"
				. $p['col'] . "\x00"
				. pack('VV', $p['offset'], $p['limit'])
				. $bson
				. (isset($p['fields']) ? bson_encode($p['fields']) : '')
			, true);

		$this->requests[$reqId] = [$p['col'], $callback, false, isset($p['parse_oplog']), isset($p['tailable'])];
	}

	/**
	 * Finds one object in collection
	 * @param array Hash of properties (offset,   opts,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function findOne($p, $callback, $key = '') {
		if (isset($p['cachekey'])) {
			$db = $this;
			$this->cache->get($p['cachekey'], function($r) use ($db,  $p,  $callback,  $key) {
				if ($r->result !== NULL) {
					call_user_func($callback, bson_decode($r->result));
				} else {
					unset($p['cachekey']);
					$db->findOne($p, $callback, $key);
				}
			});
			
			return;
		}
		
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
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
		
		if (
			isset($p['fields']) 
			&& is_string($p['fields'])
		) {
			$e = explode(',', $p['fields']);
			$p['fields'] = [];
	
			foreach ($e as &$f) {
				$p['fields'][$f] = 1;
			}
		}
		
		if (is_string($p['where'])) {
			$p['where'] = new MongoCode($p['where']);
		}
		
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
				elseif ($k === 'parse_oplog') {}
				elseif ($k == 'rp') {
					if (is_string($v)) {
						$v = ['mode' => $v];
					}
					$o['$readPreference'] = $v;
				}
				else {
					$o[$k] = $v;
				}
			}
		}
		
		if ($s) {
			$o['query'] = $p['where'];
		} else {
			$o = $p['where'];
		}
		
		$reqId = $this->request($key, self::OP_QUERY, 
			pack('V', $p['opts'])
				. $p['col'] . "\x00"
				. pack('VV', $p['offset'], -1)
				. bson_encode($o)
				. (isset($p['fields']) ? bson_encode($p['fields']) : '')
			, true);

		$this->requests[$reqId] = [$p['col'], $callback, true];
	}

	/**
	 * Counts objects in collection
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function findCount($p, $callback, $key = '') {
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
		
		$e = explode('.', $p['col']);

		$query = [
			'count' => $e[1], 
			'query' => $p['where'], 
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
			$query['where'] = new MongoCode($p['where']);
		}
		elseif (
			is_object($p['where']) 
			|| sizeof($p['where'])
		) {
			$query['query'] = $p['where'];
		}
		
		$packet = pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['col'], $callback, true];
	}

	/**
	 * Sends authenciation packet
	 * @param array Hash of properties (dbname,  user,  password,  nonce)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function auth($p, $callback, $key = '') {
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}
		
		$query = [
			'authenticate' => 1, 
			'user' => $p['user'], 
			'nonce' => $p['nonce'], 
			'key' => MongoClientAsync::getAuthKey($p['user'], $p['password'], $p['nonce']), 
		];

		$packet = pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['dbname'], $callback, true];
	}

	/**
	 * Sends request of nonce
	 * @return void
	 */
	public function getNonce($p, $callback, $key = '') {
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}
		
		$query = [
			'getnonce' => 1, 
		];
		
		$packet = pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['dbname'], $callback, true];
	}

	/**
	 * Gets last error
	 * @param string Dbname
	 * @param mixed Callback called when response received
	 * @param array Parameters.
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function lastError($db, $callback, $params = [], $key = '') {
		$e = explode('.', $db);
		$params['getlasterror'] =  1;
		$reqId = $this->request($key, self::OP_QUERY,
			pack('V', 0)
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($params)
		, true);
		$this->requests[$reqId] = [$db, $callback, true];
	}

	/**
	 * Find objects in collection using min/max specifiers
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col,  min,  max)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function range($p, $callback, $key = '') {
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
		
		$e = explode('.', $p['col']);

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
			$query['where'] = new MongoCode($p['where']);
		}
		elseif (
			is_object($p['where']) 
			|| sizeof($p['where'])
		) {
			$query['query'] = $p['where'];
		}
		
		$packet = pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['col'], $callback, true];
	}

	/**
	 * Evaluates a code on the server side
	 * @param string Code
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function evaluate($code, $callback, $key = '') {
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
		
		$query = ['$eval' => new MongoCode($code)];
		
		$packet = pack('V', $p['opts'])
			. $p['db'] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');
	
		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['db'], $callback, true];
	}

	/**
	 * Returns distinct values of the property
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col, where)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function distinct($p, $callback, $key = '') {
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
		
		$e = explode('.', $p['col']);

		$query = [
			'distinct' => $e[1], 
			'key' => $p['key'], 
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

		$packet = pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['col'], $callback, true];
	}

	/**
	 * Groupping function
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col,  reduce,  initial)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function group($p, $callback, $key = '') {
		if (!isset($p['offset'])) {
			$p['offset'] = 0;
		}
		
		if (!isset($p['limit'])) {
			$p['limit'] = -1;
		}
		
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}
		
		if (!isset($p['reduce'])) {
			$p['reduce'] = '';
		}
		
		if (is_string($p['reduce'])) {
			$p['reduce'] = new MongoCode($p['reduce']);
		}
		
		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname.'.'.$p['col'];
		}
		
		$e = explode('.', $p['col']);
		
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

		if (isset($p[$k = 'rp'])) {
			$v = $p[$k];
			if (is_string($v)) {
				$v = ['mode' => $v];
			}
			$query['$readPreference'] = $v;
		}
		
		if (isset($p[$k = 'finalize'])) {
			if (is_string($p[$k])) {
				$p[$k] = new MongoCode($p[$k]);
			}
		
			$query['group'][$k] = $p[$k];
		}
		
		if (isset($p[$k = 'keyf'])) {
			$query[$k] = $p[$k];
		}
		
		$packet = pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = [$p['col'], $callback, false];
	}

	/**
	 * Updates one object in collection
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param integer Optional. Flags.
	 * @param callback Callback (getLastError)
	 * @param array Parameters (getLastError).
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function update($col, $cond, $data, $flags = 0, $cb = NULL, $params = [], $key = '') {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		
		if (is_string($cond)) {
			$cond = new MongoCode($cond);
		}
		
		if (
			$flags 
			& 1 == 1
		) {
			//if (!isset($data['_id'])) {$data['_id'] = new MongoId();}
		}
		
		$reqId = $this->request($key, self::OP_UPDATE, 
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. pack('V', $flags)
			. bson_encode($cond)
			. bson_encode($data)
		);
		
		if ($cb !== NULL) {
			$this->lastError($col, $cb, $params, $this->lastRequestConnection);
		}
	}

	/**
	 * Updates several objects in collection
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param callback Callback
	 * @param array Parameters (getLastError).
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function updateMulti($col, $cond, $data, $cb = NULL, $params = [], $key = '') {
		$this->update($col, $cond, $data, 2, $cb, $params, $key);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param boolean Optional. Multi-flag. | array Parameters.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function upsert($col, $cond, $data, $multi = false, $cb = NULL, $params = [], $key = '') {
		$this->update($col, $cond, $data, $multi ? 3 : 1, $cb, $params, $key);
	}

	/**
	 * Inserts an object
	 * @param string Collection's name
	 * @param array Data
	 * @param string Optional. Distribution key.
	 * @return object MongoId
	 */
	public function insert($col, $doc = [], $cb = NULL, $params = [], $key = '') {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		
		if (!isset($doc['_id'])) {
			$doc['_id'] = new MongoId();
		}
		
		$reqId = $this->request($key, self::OP_INSERT, 
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. bson_encode($doc)
		);
		
		if ($cb !== NULL) {
			$this->lastError($col, $cb, $params, $this->lastRequestConnection);
		}
		
		return $doc['_id'];
	}

	/**
	 * Sends a request to kill certain cursors on the server side
	 * @param array Array of cursors
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function killCursors($cursors = [], $key = '') {
		$reqId = $this->request($key, self::OP_KILL_CURSORS, 
			"\x00\x00\x00\x00"
			. pack('V', sizeof($cursors))
			. implode('', $cursors)
		);
	}

	/**
	 * Inserts several documents
	 * @param string Collection's name
	 * @param array Array of docs
	 * @param string Optional. Distribution key.
	 * @return array
	 */
	public function insertMulti($col, $docs = [], $cb = NULL, $params = [], $key = '') {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		
		$ids = array();
		$bson = '';
		
		foreach ($docs as &$doc) {
			if (!isset($doc['_id'])) {
				$doc['_id'] = new MongoId();
			}
		
			$bson .= bson_encode($doc);
		
			$ids[] = $doc['_id'];
		}
		
		$reqId = $this->request($key, self::OP_INSERT, 
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. $bson
		);
		
		if ($cb !== NULL) {
			$this->lastError($col, $cb, $params, $this->lastRequestConnection);
		}
		
		return $ids;
	}

	/**
	 * Remove objects from collection
	 * @param string Collection's name
	 * @param array Conditions
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function remove($col, $cond = array(), $cb = NULL, $params = [], $key = '') {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		
		if (is_string($cond)) {
			$cond = new MongoCode($cond);
		}
		
		$reqId = $this->request($key, self::OP_DELETE, 
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. "\x00\x00\x00\x00"
			. bson_encode($cond)
		);
		
		if ($cb !== NULL) {
			$this->lastError($col, $cb, $params, $this->lastRequestConnection);
		}
	}

	/**
	 * Asks for more objects
	 * @param string Collection's name
	 * @param string Cursor's ID
	 * @param integer Number of objects
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function getMore($col, $id, $number, $key = '') {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}

		$reqId = $this->request($key, self::OP_GETMORE, 
			"\x00\x00\x00\x00"
			. $col . "\x00"
			. pack('V', $number)
			. $id
		);
		$this->requests[$reqId] = array($id);
	}

	/**
	 * Returns an object of collection
	 * @param string Collection's name
	 * @return MongoClientAsyncCollection
	 */
	public function getCollection($col) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		} else {
            $collName = explode('.', $col);
            $this->dbname = $collName[0];
        }

		if (isset($this->collections[$col])) {
			return $this->collections[$col];
		}
		
		return $this->collections[$col] = new MongoClientAsyncCollection($col, $this);
	}

	/**
	 * Magic getter-method. Proxy for getCollection. 
	 * @param string Collection's name
	 * @return MongoClientAsyncCollection
	 */
	public function __get($name) {
		return $this->getCollection($name);
	}
}
class MongoClientAsyncConnectionFinished extends Exception {}
