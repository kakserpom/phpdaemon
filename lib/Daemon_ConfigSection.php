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
	
	public function __construct($arr = []) {
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
		$arr = [];
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
		return $this->offsetGet($offset) !== null;
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

 	/**
	 * Impose default config
	 * @param array {"setting": "value"}
	 * @return void
	 */
	public function imposeDefault($settings = []) {
		foreach ($settings as $name => $value) {
			$name = strtolower(str_replace('-', '', $name));
			if (!isset($this->{$name})) {
				if (is_scalar($value))	{
					$this->{$name} = new Daemon_ConfigEntry($value);
				} else {
					$this->{$name} = $value;
				}
			} elseif ($value instanceof Daemon_ConfigSection) {
				$value->imposeDefault($value);
			}	else {
				$current = $this->{$name};
			  if (is_scalar($value))	{
					$this->{$name} = new Daemon_ConfigEntry($value);
				} else {
					$this->{$name} = $value;
				}
				
				$this->{$name}->setHumanValue($current->value);
				$this->{$name}->source = $current->source;
				$this->{$name}->revision = $current->revision;
			}
		}
	}
}
