<?php
namespace PHPDaemon\Clients\Mongo;

/**
 * @package    Applications
 * @subpackage MongoClientAsync
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Collection {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var Pool Related Pool object
	 */
	public $pool;

	/**
	 * @var string Name of collection
	 */
	public $name;

	/**
	 * Contructor of MongoClientAsyncCollection
	 * @param  string $name Name of collection
	 * @param  Pool   $pool Pool
	 * @return void
	 */
	public function __construct($name, $pool) {
		$this->name = $name;
		$this->pool = $pool;
	}

	/**
	 * Finds objects in collection
	 * @param  callable $cb Callback called when response received
	 * @param  array    $p  Hash of properties (offset, limit, opts, tailable, where, col, fields, sort, hint, explain, snapshot, orderby, parse_oplog)
	 * @callback $cb ( )
	 * @return void
	 */
	public function find($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->find($p, $cb);
	}

	/**
	 * Finds objects in collection and fires callback when got all objects
	 * @param  callable $cb Callback called when response received
	 * @param  array    $p  Hash of properties (offset, limit, opts, tailable, where, col, fields, sort, hint, explain, snapshot, orderby, parse_oplog)
	 * @callback $cb ( )
	 * @return void
	 */
	public function findAll($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->findAll($p, $cb);
	}

	/**
	 * Finds one object in collection
	 * @param  callable $cb Callback called when response received
	 * @param  array    $p  Hash of properties (offset,  opts, where, col, fields, sort, hint, explain, snapshot, orderby, parse_oplog)
	 * @callback $cb ( )
	 * @return void
	 */
	public function findOne($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->findOne($p, $cb);
	}

	/**
	 * Counts objects in collection
	 * @param  callable $cb Callback called when response received
	 * @param  array    $p  Hash of properties (offset, limit, opts, where, col)
	 * @callback $cb ( )
	 * @return void
	 */
	public function count($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->findCount($p, $cb);
	}

	/**
	 * Ensure index
	 * @param  array    $keys    Keys
	 * @param  array    $options Optional. Options
	 * @param  callable $cb      Optional. Callback called when response received
	 * @callback $cb ( )
	 * @return void
	 */
	public function ensureIndex($keys, $options = [], $cb = null) {
		$this->pool->ensureIndex($this->name, $keys, $options, $cb);
	}

	/**
	 * Groupping function
	 * @param  callable $cb Callback called when response received
	 * @param  array    $p  Hash of properties (offset, limit, opts, key, col, reduce, initial)
	 * @callback $cb ( )
	 * @return void
	 */
	public function group($cb, $p = []) {
		$p['col'] = $this->name;
		$this->pool->group($p, $cb);
	}

	/**
	 * Inserts an object
	 * @param  array    $doc    Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return MongoId
	 */
	public function insert($doc, $cb = null, $params = null) {
		return $this->pool->insert($this->name, $doc, $cb, $params);
	}

	/**
	 * Inserts an object
	 * @param  array    $doc    Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return MongoId
	 */
	public function insertOne($doc, $cb = null, $params = null) {
		return $this->pool->insert($this->name, $doc, $cb, $params);
	}

	/**
	 * Inserts several documents
	 * @param  array    $docs   Array of docs
	 * @param  callable $cb     Optional. Callback called when response received.
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return array    IDs
	 */
	public function insertMulti($docs, $cb = null, $params = null) {
		return $this->pool->insertMulti($this->name, $docs, $cb, $params);
	}

	/**
	 * Updates one object in collection
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  integer  $flags  Optional. Flags
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function update($cond, $data, $flags = 0, $cb = null, $params = null) {
		$this->pool->update($this->name, $cond, $data, $flags, $cb, $params);
	}


	/**
	 * Updates one object in collection
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function updateOne($cond, $data, $cb = null, $params = null) {
		$this->pool->updateOne($this->name, $cond, $data, $cb, $params);
	}

	/**
	 * Updates one object in collection
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function updateMulti($cond, $data, $cb = null, $params = null) {
		$this->pool->updateMulti($this->name, $cond, $data, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists, insert if not exists)
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  boolean  $multi  Optional. Multi-flag
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function upsert($cond, $data, $multi = false, $cb = NULL, $params = null) {
		$this->pool->upsert($this->name, $cond, $data, $multi, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists, insert if not exists)
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function upsertOne($cond, $data, $cb = NULL, $params = null) {
		$this->pool->upsertOne($this->name, $cond, $data, $cb, $params);
	}

	/**
	 * Upserts an object (updates if exists, insert if not exists)
	 * @param  array    $cond   Conditions
	 * @param  array    $data   Data
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function upsertMulti($cond, $data, $cb = NULL, $params = null) {
		$this->pool->upsertMulti($this->name, $cond, $data, $cb, $params);
	}

	/**
	 * Removes objects from collection
	 * @param  array    $cond   Conditions
	 * @param  callable $cb     Optional. Callback called when response received
	 * @param  array    $params Optional. Params
	 * @callback $cb ( )
	 * @return void
	 */
	public function remove($cond = [], $cb = NULL, $params = null) {
		$this->pool->remove($this->name, $cond, $cb);
	}

	/**
	 * Evaluates a code on the server side
	 * @param  string   $code Code
	 * @param  callable $cb   Callback called when response received
	 * @callback $cb ( )
	 * @return void
	 */
	public function evaluate($code, $cb) {
		$this->pool->evaluate($code, $cb);
	}


	/**
	 * Aggregate
	 * @param  array    $p  Params
	 * @param  callable $cb Callback called when response received
	 * @callback $cb ( )
	 * @return void
	 */
	public function aggregate($p, $cb) {
		$p['col'] = $this->name;
		$this->pool->aggregate($p, $cb);
	}

	/**
	 * Generation autoincrement
	 * @param  callable $cb    Called when response received
	 * @param  boolean  $plain Plain?
	 * @callback $cb ( )
	 * @return void
	 */
	public function autoincrement($cb, $plain = false) {
		$e = explode('.', $this->name);
		$col = (isset($e[1]) ? $e[0] . '.' : '') . 'autoincrement';
		$this->pool->{$col}->findAndModify([
			'query' => ['_id' => isset($e[1]) ? $e[1] : $e[0]],
			'update' => ['$inc' => ['seq' => 1]],
			'new' => true,
			'upsert' => true,
		], $plain ? function ($lastError) use ($cb) {
			call_user_func($cb, isset($lastError['value']['seq']) ? $lastError['value']['seq'] : false);
		} : $cb);
	}

	/**
	 * Generation autoincrement
	 * @param  array    $p  Params
	 * @param  callable $cb Callback called when response received
	 * @callback $cb ( )
	 * @return void
	 */
	public function findAndModify($p, $cb) {
		$p['col'] = $this->name;
		$this->pool->findAndModify($p, $cb);
	}
}
