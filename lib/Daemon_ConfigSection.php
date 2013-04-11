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
	
	/**
	 * Constructor
	 * @param hash
	 * @return object
	 */
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

	/**
	 * Count elements
	 * @return number 
	 */
	public function count() {
		return count($this);
	}
	
	/**
	 * toArray handler
	 * @return hash
	 */
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

	/**
	 * Get real property name
	 * @param string Property name
	 * @return string Real property name
	 */
	public function getRealPropertyName($prop) {
		return str_replace('-', '', strtolower($prop));
	}

	/**
	 * Checks if property exists
	 * @param string Property name
	 * @return boolean Exists?
	 */
	
	public function offsetExists($prop) {
		$prop = $this->getRealPropertyName($prop);
		return propery_exists($this, $prop);
	}

	/**
	 * Get property by name
	 * @param string Property name
	 * @return mixed
	 */
	public function offsetGet($prop) {
		$prop = $this->getRealPropertyName($prop);
		return isset($this->{$prop}) ? $this->{$prop}->value : null;
	}

	/**
	 * Set property
	 * @param string Property name
	 * @param mixed Value
	 * @return void
	 */
	public function offsetSet($prop,$value) {
		$prop = $this->getRealPropertyName($prop);
		$this->{$prop} = $value;
	}

	/**
	 * Unset property
	 * @param string Property name
	 * @return void
	 */
	public function offsetUnset($prop) {
		$prop = $this->getRealPropertyName($prop);
		unset($this->{$prop});
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
			  if (!is_object($value)) {
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
