<?php
class MongoClientCollection {
    /**
     * @var MongoClient
     */
	public $pool;
	public $name; // Name of collection.

	/**
	 * Contructor of MongoClientCOllection
	 * @param string Name of collection
	 * @param object Pool
	 * @return void
	 */
	public function __construct($name, $pool) {
		$this->name = $name;
		$this->pool = $pool;
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

		return $this->pool->find($p, $callback, $key);
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

		return $this->pool->findOne($p, $callback, $key);
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
		return $this->pool->count($p, $callback, $key);
	}

	/**
	 * Groupping function
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col,  reduce,  initial)
	 * @return void
	 */
	public function group($callback, $p = array(), $key = '') {
		$p['col'] = $this->name;
	
		return $this->pool->group($p, $callback, $key);
	}

	/**
	 * Inserts an object
	 * @param array Data
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function insert($doc, $cb = NULL, $key = '') {
		return $this->pool->insert($this->name, $doc, $cb, $key);
	}

	/**
	 * Inserts several documents
	 * @param array Array of docs
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function insertMulti($docs, $cb = NULL, $key = '') {
		return $this->pool->insertMulti($this->name, $docs, $cb, $key);
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
		return $this->pool->update($this->name, $cond, $data, $flags, $cb, $key);
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
		return $this->pool->updateMulti($this->name, $cond, $data, $cb, $key);
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
		return $this->pool->upsert($this->name, $cond, $data, $multi, $cb, $key);
	}

	/**
	 * Removes objects from collection
	 * @param array Conditions
	 * @param mixed Optional. Callback called when response received.
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function remove($cond = array(), $cb = NULL, $key = '') {
		return $this->pool->remove($this->name, $cond, $cb, $key);
	}

    /**
     * Evaluates a code on the server side
     * @param string Code
     * @param mixed Callback called when response received
     * @param string Optional. Distribution key
     * @return void
     */
    public function evaluate($code, $callback, $key = '')
    {
        $this->pool->evaluate($code, $callback, $key);
    }

    /**
     * Generation autoincrement
     * @param Closure $callback called when response received
     * @param string $key Optional. Distribution key
     * @return void
     */
    public function autoincrement($callback, $key = '')
    {
        $this->evaluate(
            'function () { '
                . 'return db.autoincrement.findAndModify({ '
                . 'query: {"_id":"' . $this->name . '"}, update: {$inc:{"id":1}}, new: true, upsert: true }); }',
            function ($res) use ($callback) {
                call_user_func($callback, $res);
            },
            $key
        );
    }
}
