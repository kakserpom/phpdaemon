<?php

/**
 * Config section
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigSection implements ArrayAccess, Countable {

	public $source;
	public $revision;
	
	public function __construct($arr = array()) {
		foreach ($arr as $k => $v) {
			if (!is_object($v)) {
				$e = new Daemon_ConfigEntry;
				$e->setHumanValue($v);
				$this->{$k} = $e;
			} else {
				$this->{$k} = $v;
			}
		}
	}

	public function count() {
		$c = 0;

		foreach ($this as $prop) {
			++$c;
		}

		return $c;
	}
	
	public function toArray() {
		$arr = array();
		foreach ($this as $k => $entry) {
			if (!$entry instanceof Daemon_ConfigEntry)	{
				continue;
			}
			$arr[$k] = $entry->value;
		}
		return $arr;
	}

	public function getRealOffsetName($offset) {
		return str_replace('-', '', strtolower($offset));
	}

	public function offsetExists($offset) {
		return $this->offsetGet($offset) !== NULL;
	}

	public function offsetGet($offset) {
		return $this->{$this->getRealOffsetName($offset)}->value;
	}

	public function offsetSet($offset,$value) {
		$this->{$this->getRealOffsetName($offset)} = $value;
	}

	public function offsetUnset($offset) {
		unset($this->{$this->getRealOffsetName($offset)});
	}

}
