<?php
class MongoClientAsyncCollection {
    /** Related Pool object
     * @var MongoClient
     */
	public $pool;

	/** Name of collection.
     * @var string
     */
	public $name;

	/**
	 * Contructor of MongoClientAsyncCollection
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
	 * @return void
	 */
	public function find($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->find($p, $cb);
	}

	/**
	 * Finds one object in collection
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,   opts,  where,  col,  fields,  sort,  hint,  explain,  snapshot,  orderby,  parse_oplog)
	 * @return void
 	*/
	public function findOne($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->findOne($p, $cb);
	}

	/**
	 * Counts objects in collection
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  where,  col)
	 * @param string Optional. Distribution key.
	 * @return void
	 */
	public function count($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->findCount($p, $cb);
	}

	/**
	 * Groupping function
	 * @param mixed Callback called when response received
	 * @param array Hash of properties (offset,  limit,  opts,  key,  col,  reduce,  initial)
	 * @return void
	 */
	public function group($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->group($p, $cb);
	}

	/**
	 * Inserts an object
	 * @param array Data
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return MongoId
	 */
	public function insert($doc, $cb = null, $params = null) {
		return $this->pool->insert($this->name, $doc, $cb, $params);
	}

	/**
	 * Inserts several documents
	 * @param array Array of docs
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return array IDs
	 */
	public function insertMulti($docs, $cb = null, $params = null) {
		return $this->pool->insertMulti($this->name, $docs, $cb, $params);
	}

	/**
	 * Updates one object in collection
	 * @param array Conditions
	 * @param array Data
	 * @param integer Optional. Flags.
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return void
	 */
	public function update($cond, $data, $flags = 0, $cb = null, $params = null) {
		$this->pool->update($this->name, $cond, $data, $flags, $cb, $params);
	}

	/**
	 * Updates several objects in collection
	 * @param array Conditions
	 * @param array Data
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return void
	 */
	public function updateMulti($cond, $data, $cb = NULL, $params = null) {
		$this->pool->updateMulti($this->name, $cond, $data, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists,  insert if not exists)
	 * @param array Conditions
	 * @param array Data
	 * @param boolean Optional. Multi-flag.
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return void
	 */
	public function upsert($cond, $data, $multi = false, $cb = NULL, $params = null) {
		$this->pool->upsert($this->name, $cond, $data, $multi, $cb, $params);
	}

	/**
	 * Removes objects from collection
	 * @param array Conditions
	 * @param mixed Optional. Callback called when response received.
	 * @param array Optional. Params.
	 * @return void
	 */
	public function remove($cond = array(), $cb = NULL, $params = null) {
		$this->pool->remove($this->name, $cond, $cb);
	}

    /**
     * Evaluates a code on the server side
     * @param string Code
     * @param mixed Callback called when response received
     * @return void
     */
    public function evaluate($code, $cb) {
		$this->pool->evaluate($code, $cb);
    }

    /**
     * Generation autoincrement
     * @param Closure $cb called when response received
     * @return void
     */
    public function autoincrement($cb) {
		$this->evaluate('function () { '
			. 'return db.autoincrement.findAndModify({ '
			. 'query: {"_id":' . json_encode($this->name) . '}, update: {$inc:{"id":1}}, new: true, upsert: true }); }',
		$cb);
    }
}
