<?php

/**
 * @package Applications
 * @subpackage MongoClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MongoClient extends AsyncServer {

	public $sessions    = array(); // Active sessions
	public $servers     = array(); // Array of servers
	public $servConn    = array(); // Active connections
	public $servConnFree = array(); // Free connections
	public $requests    = array(); // Pending requests
	public $cursors     = array(); // Active cursors
	public $lastReqId   = 0;       // ID of the last request
	public $collections = array(); // Objects of MongoClientCollection
	public $dbname      = '';      // Current database
	public $lastRequestSession;    // Holds last used MongoClientSession object.

	/* Codes of operations */
	const OP_REPLY        = 1; 
	const OP_MSG          = 1000;
	const OP_UPDATE       = 2001;
	const OP_INSERT       = 2002;
	const OP_QUERY        = 2004;
	const OP_GETMORE      = 2005;
	const OP_DELETE       = 2006;
	const OP_KILL_CURSORS = 2007;

	/**/
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
			
			'maxconnectionsperserver' => 32,
		);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		$this->cache = Daemon::$appResolver->getInstanceByAppName('MemcacheClient');
		$servers = explode(',', $this->config->servers->value);

		foreach ($servers as $s) {
			$this->addServer($s);
		}
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
	public function addServer($url, $weight = NULL) {
		$this->servers[$url] = $weight;
	}

	/**
	 * Gets the key
	 * @param string Key
	 * @param integer Opcode (see constants above)
	 * @param string Data
	 * @param boolean Is an answer expected?
	 * @return integer Request ID
	 * @throws MongoClientSessionFinished
	 */
	public function request($key, $opcode, $data, $reply = false) {
		if (
			(is_object($key) 
			&& ($key instanceof MongoClientSession))
		) {
			$sess = $key;
			if ($sess->finished) {
				throw new MongoClientSessionFinished;
			}
		} else {
			$connId = $this->getConnectionByKey($key);
			$sess = $this->sessions[$connId];
		}

		$this->lastRequestSession = $sess;

		$sess->write($p = pack('VVVV', strlen($data)+16, ++$this->lastReqId, 0, $opcode) . $data);
		
		if ($reply) {
			$sess->busy = true;
			unset($this->servConnFree[$sess->url][$sess->connId]);
		}
		
		return $this->lastReqId;
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

		$this->requests[$reqId] = array($p['col'], $callback, false, isset($p['parse_oplog']), isset($p['tailable']));
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

		$this->requests[$reqId] = array($p['col'], $callback, true);
	}

	/**
	 * Counts objects in collection
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col)
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function count($p, $callback, $key = '') {
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
			$p['where'] = array();
		}
		
		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}
		
		$e = explode('.', $p['col']);

		$query = array(
			'count' => $e[1], 
			'query' => $p['where'], 
			'fields' => array('_id' => 1), 
		);

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
		$this->requests[$reqId] = array($p['col'], $callback, true);
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
		
		$query = array(
			'authenticate' => 1, 
			'user' => $p['user'], 
			'nonce' => $p['nonce'], 
			'key' => MongoClient::getAuthKey($p['user'], $p['password'], $p['nonce']), 
		);

		$packet = pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = array($p['dbname'], $callback, true);
	}

	/**
	 * Sends request of nonce
	 * @return void
	 */
	public function getNonce($p, $callback, $key = '') {
		if (!isset($p['opts'])) {
			$p['opts'] = 0;
		}
		
		$query = array(
			'getnonce' => 1, 
		);
		
		$packet = pack('V', $p['opts'])
			. $p['dbname'] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = array($p['dbname'], $callback, true);
	}

	/**
	 * Gets last error
	 * @param string Dbname
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function lastError($db, $callback, $key = '') {
		$e = explode('.', $db);

		$packet = pack('V', 0)
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', 0, -1)
			. bson_encode(array('getlasterror' => 1));

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = array($db, $callback, true);
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
			$p['where'] = array();
		}
		
		if (!isset($p['min'])) {
			$p['min'] = array();
		}
		
		if (!isset($p['max'])) {
			$p['max'] = array();
		}
		
		if (strpos($p['col'], '.') === false) {
			$p['col'] = $this->dbname . '.' . $p['col'];
		}
		
		$e = explode('.', $p['col']);

		$query = array(
			'$query' => $p['where'], 
		);

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
		$this->requests[$reqId] = array($p['col'], $callback, true);
	}

	/**
	 * Evaluates a code on the server side
	 * @param string Code
	 * @param mixed Callback called when response received
	 * @param string Optional. Distribution key
	 * @return void
	 */
	public function evaluate($code, $callback, $key = '') {
		$p = array();
		
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
		
		$query = array('$eval' => new MongoCode($code));
		
		$packet = pack('V', $p['opts'])
			. $p['db'] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');
	
		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = array($p['db'], $callback, true);
	}

	/**
	 * Returns distinct values of the property
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col)
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

		$query = array(
			'distinct' => $e[1], 
			'key' => $p['key'], 
		);

		$packet = pack('V', $p['opts'])
			. $e[0] . '.$cmd' . "\x00"
			. pack('VV', $p['offset'], $p['limit'])
			. bson_encode($query)
			. (isset($p['fields']) ? bson_encode($p['fields']) : '');

		$reqId = $this->request($key, self::OP_QUERY, $packet, true);
		$this->requests[$reqId] = array($p['col'], $callback, true);
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
		
		$query = array(
			'group' => array(
				'ns'      => $e[1], 
				'key'     => $p['key'], 
				'$reduce' => $p['reduce'], 
				'initial' => $p['initial'], 
			)
		);
		
		if (isset($p[$k = 'cond'])) {
			$query['group'][$k]= $p[$k];
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
		$this->requests[$reqId] = array($p['col'], $callback, false);
	}

	/**
	 * Called when worker is going to update configuration
	 * @return void
	 */
	public function updateWorker() { }

	/**
	 * Handles the worker's status.	
	 * @param int Status code.
	 * @return boolean Result.
	 */
	public function handleStatus($ret) {
		if ($ret === 2) {
			// script update
			$r = $this->updateWorker();
		}
		elseif ($ret === 3) {
			// graceful worker shutdown for restart
			$r = $this->shutdown(true);
		}
		elseif ($ret === 5) {
			// shutdown worker
			$r = $this->shutdown();
		} else {
			$r = true;
		}

		if ($r === NULL) {
			$r = true;
		}

		return $r;
	}

	/**
	 * Updates one object in collection
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param integer Optional. Flags.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function update($col, $cond, $data, $flags = 0, $cb = NULL, $key = '') {
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
			$this->lastError($col, $cb, $this->lastRequestSession);
		}
	}

	/**
	 * Updates several objects in collection
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function updateMulti($col, $cond, $data, $cb = NULL, $key = '') {
		return $this->update($col, $cond, $data, 2, $cb, $key);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param string Collection's name
	 * @param array Conditions
	 * @param array Data
	 * @param boolean Optional. Multi-flag.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function upsert($col, $cond, $data, $multi = false, $cb = NULL, $key = '') {
		return $this->update($col, $cond, $data, $multi ? 3 : 1, $cb, $key);
	}

	/**
	 * Inserts an object
	 * @param string Collection's name
	 * @param array Data
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function insert($col, $doc = array(), $cb = NULL,  $key = '') {
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
			$this->lastError($col, $cb, $this->lastRequestSession);
		}
		
		return $doc['_id'];
	}

	/**
	 * Sends a request to kill certain cursors on the server side
	 * @param array Array of cursors
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function killCursors($cursors = array(), $key = '') {
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
	 * @return void
	 */
	public function insertMulti($col, $docs = array(), $cb = NULL, $key = '') {
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
			$this->lastError($col, $cb, $this->lastRequestSession);
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
	public function remove($col, $cond = array(), $cb = NULL, $key = '') {
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
			$this->lastError($col, $cb, $this->lastRequestSession);
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
	 * @return object MongoClientCollection
	 */
	public function getCollection($col) {
		if (strpos($col, '.') === false) {
			$col = $this->dbname . '.' . $col;
		}
		
		if (isset($this->collections[$col])) {
			return $this->collections[$col];
		}
		
		return $this->collections[$col] = new MongoClientCollection($col, $this);
	}

	/**
	 * Magic getter-method. Proxy for getCollection. 
	 * @param string Collection's name
	 * @return void
	 */
	public function __get($name) {
		return $this->getCollection($name);
	}

	/**
	 * Establishes connection
	 * @param string URL
	 * @return integer Connection's ID.
	 */
	public function getConnection($url) {
		if (isset($this->servConn[$url])) {
			while (($c = array_pop($this->servConnFree[$url])) !== null) {
				if (isset($this->sessions[$c])) {
					return $c;
				}
			}
			if (sizeof($this->servConn[$url]) >= $this->config->maxconnectionsperserver->value) {
			 $c = $this->servConn[$url][array_rand($this->servConn[$url])];;
			 return $c;
			}
		} else {
			$this->servConn[$url] = array();
			$this->servConnFree[$url] = array();
		}
		
		$u = parse_url($url);
		
		if (!isset($u['port'])) {
			$u['port'] = $this->config->port->value;
		}
		
		$connId = $this->connectTo($u['host'], $u['port']);
		$session = $this->sessions[$connId] = new MongoClientSession($connId, $this);
		$session->url = $url;

		if (isset($u['user'])) {
			$session->user = $u['user'];
		}
		
		if (isset($u['pass'])) {
			$session->password = $u['pass'];
		}
		
		if (isset($u['path'])) {
			$session->dbname = ltrim($u['path'], '/');
		}
		
		$this->servConn[$url][$connId] = $connId;
		$this->servConnFree[$url][$connId] = $connId;

		if ($session->user !== NULL) {
			$this->getNonce(array(
				'dbname' => $session->dbname), 
				function($result) use ($session) {
					$session->appInstance->auth(
						array(
							'user'     => $session->user, 
							'password' => $session->password, 
							'nonce'    => $result['nonce'], 
							'dbname'   => $session->dbname, 
						), 
						function($result) use ($session) {
							if (!$result['ok']) {
								Daemon::log('MongoClient: authentication error with ' . $session->url . ': ' . $result['errmsg']);
							}
						}, 
						$session
					);
				}, $session
			);
		}
		return $connId;
	}

	/**
	 * Establishes connection
	 * @param string Distrubution key
	 * @return integer Connection's ID.
	 */
	public function getConnectionByKey($key) {
		srand(crc32($key));
		$url = array_rand($this->servers);
		srand();  
		
		return $this->getConnection($url);
	}
}

class MongoClientSession extends SocketSession {
	public $url;               // url
	public $user;              // Username
	public $password;          // Password
	public $dbname;            // Database name
	public $busy = false;      // Is this session busy?

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		$l = strlen($this->buf);

		if ($l < 16) {
			// we have not enough data yet
			return;
		}
		
		$h = unpack('Vlen/VreqId/VresponseTo/VopCode', binarySubstr($this->buf, 0, 16));
		$plen = (int) $h['len'];
		
		if ($plen > $l) {
			// we have not enough data yet
			return;
		} 
		
		if ($h['opCode'] === MongoClient::OP_REPLY) {
			$r = unpack('Vflag/VcursorID1/VcursorID2/Voffset/Vlength', binarySubstr($this->buf, 16, 20));
					//Daemon::log(array($r,$h));
			$r['cursorId'] = binarySubstr($this->buf, 20, 8);
			$id = (int) $h['responseTo'];
			$flagBits = str_pad(strrev(decbin($r['flag'])), 8, '0', STR_PAD_LEFT);
			$cur = ($r['cursorId'] !== "\x00\x00\x00\x00\x00\x00\x00\x00"?'c' . $r['cursorId'] : 'r' . $h['responseTo']);

			if (
				isset($this->appInstance->requests[$id][2]) 
				&& ($this->appInstance->requests[$id][2] === false) 
				&& !isset($this->appInstance->cursors[$cur])
			) {
				$this->appInstance->cursors[$cur] = new MongoClientCursor($cur, $this->appInstance->requests[$id][0], $this);
				$this->appInstance->cursors[$cur]->failure = $flagBits[1] == '1';
				$this->appInstance->cursors[$cur]->await = $flagBits[3] == '1';
				$this->appInstance->cursors[$cur]->callback = $this->appInstance->requests[$id][1];
				$this->appInstance->cursors[$cur]->parseOplog = 
					isset($this->appInstance->requests[$id][3]) 
					&& $this->appInstance->requests[$id][3];
				$this->appInstance->cursors[$cur]->tailable = 
					isset($this->appInstance->requests[$id][4]) 
					&& $this->appInstance->requests[$id][4];
			}
			//Daemon::log(array(Debug::exportBytes($cur),get_Class($this->appInstance->cursors[$cur])));
			
			if (
				isset($this->appInstance->cursors[$cur]) 
				&& (
					($r['length'] === 0) 
					|| (binarySubstr($cur, 0, 1) === 'r')
				)
			) {

				if ($this->appInstance->cursors[$cur]->tailable) {
					if ($this->appInstance->cursors[$cur]->finished = ($flagBits[0] == '1')) {
						$this->appInstance->cursors[$cur]->destroy();
					}
				} else {
					$this->appInstance->cursors[$cur]->finished = true;
				}
			}
			
			$p = 36;			
			while ($p < $plen) {
				$dl = unpack('Vlen', binarySubstr($this->buf, $p, 4));
				$doc = bson_decode(binarySubstr($this->buf, $p, $dl['len']));

				if (
					isset($this->appInstance->cursors[$cur]) 
					&& @$this->appInstance->cursors[$cur]->parseOplog 
					&& isset($doc['ts'])
				) {
					$tsdata = unpack('Vsec/Vinc', binarySubstr($this->buf, $p + 1 + 4 + 3, 8));
					$doc['ts'] = $tsdata['sec'] . ' ' . $tsdata['inc'];
				}

				$this->appInstance->cursors[$cur]->items[] = $doc;
				$p += $dl['len'];
			}
			
			$this->busy = false;
			$this->appInstance->servConnFree[$this->url][$this->connId] = $this->connId;
			
			if (
				isset($this->appInstance->requests[$id][2]) 
				&& $this->appInstance->requests[$id][2]
			) {
				call_user_func(
					$this->appInstance->requests[$id][1], 
					isset($this->appInstance->cursors[$cur]->items[0]) ? $this->appInstance->cursors[$cur]->items[0] : false
				);

				if (isset($this->appInstance->cursors[$cur])) {
					if ($this->appInstance->cursors[$cur] instanceof MongoClientCursor) {
						$this->appInstance->cursors[$cur]->destroy();
					} else {
						unset($this->appInstance->cursors[$cur]);
					}
				}
			} 
			elseif (isset($this->appInstance->cursors[$cur])) {
				call_user_func($this->appInstance->cursors[$cur]->callback, $this->appInstance->cursors[$cur]);
			}
			
			unset($this->appInstance->requests[$id]);
		}
		
		$this->buf = binarySubstr($this->buf, $plen);
		
		goto start;
	}

	/**
	 * Called when session finished
	 * @return void
	 */
	public function onFinish() {
		$this->finished = true;

		unset($this->appInstance->servConn[$this->url][$this->connId]);
		unset($this->appInstance->servConnFree[$this->url][$this->connId]);
		unset($this->appInstance->sessions[$this->connId]);
	}
	
}

class MongoClientCollection {
	public $appInstance;
	public $name; // Name of collection.

	/**
	 * Contructor of MongoClientCOllection
	 * @param string Name of collection
	 * @param string Application's instance
	 * @return void
	 */
	public function __construct($name, $appInstance) {
		$this->name = $name;
		$this->appInstance = $appInstance;
	}

	/**
	 * Finds objects in collection
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  tailable,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function find($callback, $p = array(), $key = '') {
		$p['col'] = $this->name;

		return $this->appInstance->find($p, $callback, $key);
	}

	/**
	 * Finds one object in collection
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,   opts,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @param string Optional. Distribution key.
	 * @return void
 	*/
	public function findOne($callback, $p = array(), $key = '') {
		$p['col'] = $this->name;

		return $this->appInstance->findOne($p, $callback, $key);
	}

	/**
	 * Counts objects in collection
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col)
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function count($callback, $p = array(), $key = '') {
		$p['col'] = $this->name;
		return $this->appInstance->count($p, $callback, $key);
	}

	/**
	 * Groupping function
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col,  reduce,  initial)
	 * @return void
	 */
	public function group($callback, $p = array(), $key = '') {
		$p['col'] = $this->name;
	
		return $this->appInstance->group($p, $callback, $key);
	}

	/**
	 * Inserts an object
	 * @param array Data
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function insert($doc, $cb = NULL, $key = '') {
		return $this->appInstance->insert($this->name, $doc, $cb, $key);
	}

	/**
	 * Inserts several documents
	 * @param array Array of docs
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function insertMulti($docs, $cb = NULL, $key = '') {
		return $this->appInstance->insertMulti($this->name, $docs, $cb, $key);
	}

	/**
	 * Updates one object in collection
	 * @param array Conditions
	 * @param array Data
	 * @param integer Optional. Flags.
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function update($cond, $data, $flags = 0, $cb = NULL, $key = '') {
		return $this->appInstance->update($this->name, $cond, $data, $flags, $cb, $key);
	}

	/**
	 * Updates several objects in collection
	 * @param array Conditions
	 * @param array Data
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function updateMulti($cond, $data, $cb = NULL, $key = '') {
		return $this->appInstance->updateMulti($this->name, $cond, $data, $cb, $key);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param array Conditions
	 * @param array Data
	 * @param boolean Optional. Multi-flag.
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function upsert($cond, $data, $multi = false, $cb = NULL, $key = '') {
		return $this->appInstance->upsert($this->name, $cond, $data, $multi, $cb, $key);
	}

	/**
	 * Removes objects from collection
	 * @param array Conditions
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function remove($cond = array(), $cb = NULL, $key = '') {
		return $this->appInstance->remove($this->name, $cond, $cb, $key);
	}
	
}

class MongoClientCursor {
	public $id;                 // Cursor's ID.
	public $appInstance;        // Application's instance
	public $col;                // Collection's name.
	public $items = array();    // Array of objects
	public $item;               // Current object
	public $session;            // Network session
	public $finished = false;   // Is this cursor finished?
	public $failure = false;    // Is this query failured?
	public $await = false;      // awaitCapable?
	public $destroyed = false;  // Is this cursor destroyed?

	/**
	 * Constructor
	 * @param string Cursor's ID
	 * @param string Collection's name
	 * @param object Network session (MongoClientSession), 
	 * @return void
	 */
	public function __construct($id, $col, $session) {
		$this->id = $id;
		$this->col = $col;
		$this->session = $session;
		$this->appInstance = $session->appInstance;
	}

	/**
	 * Asks for more objects
	 * @param integer Number of objects
	 * @return void
	 */
	public function getMore($number = 0) {
		//if ($this->tailable && $this->await) {return true;}
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->appInstance->getMore($this->col, binarySubstr($this->id, 1), $number, $this->session);
		}

		return true;
	}

	/**
	 * Destroys the cursors
	 * @return boolean Success
	 */
	public function destroy() {
		$this->destroyed = true;
		unset($this->appInstance->cursors[$this->id]);
	
		return true;
	}

	/**
	 * Cursor's destructor. Sends a signal to the server.
	 * @return void
	 */
	public function __destruct() {
		if (binarySubstr($this->id, 0, 1) === 'c') {
			$this->appInstance->killCursors(array(binarySubstr($this->id, 1)));
		}
	}
}

class MongoClientSessionFinished extends Exception {}
