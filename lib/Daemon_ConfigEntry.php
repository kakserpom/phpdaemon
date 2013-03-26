<?php

/**
 * Config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntry {

	public $defaultValue;
	public $value;
	public $humanValue;
	public $source;
	public $revision;
	public $hasDefaultValue = FALSE;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct() {
		if (func_num_args() == 1) {
			$this->setDefaultValue(func_get_arg(0));
			$this->setHumanValue(func_get_arg(0));
		}
	}
	
	/**
	 * Set value
	 * @param mixed
	 * @return void
	 */
	public function setValue($value) {
		$old = $this->value;
		$this->value = $value;
		$this->humanValue = static::PlainToHuman($value);
		$this->onUpdate($old);
	}

	/**
	 * Set value type
	 * @param mixed
	 * @return void
	 */
	public function setValueType($type) {
		$this->valueType = $type;
	}

	/**
	 * Reset to default
	 * @return boolean Success
	 */
	public function resetToDefault() {
		if ($this->hasDefaultValue) {
			$this->setHumanValue($this->defaultValue);
			return true;
		}
		return false;
	}
 

 	/**
	 * Set default value
	 * @param mixed
	 * @return void
	 */
	public function setDefaultValue($value) {
		$this->defaultValue = $value;
		$this->hasDefaultValue = true;
	}

	/**
	 * Set human-readable value
	 * @param mixed
	 * @return void
	 */
	public function setHumanValue($value) {
		$this->humanValue = $value;
		$old = $this->value;
		$this->value = static::HumanToPlain($value);
		$this->onUpdate($old);
	}

	/**
	 * Converts human-readable value to plain
	 * @param mixed
	 * @return mixed
	 */
	public static function HumanToPlain($value) {
		return $value;
	}

	/**
	 * Converts plain value to human-readable 
	 * @param mixed
	 * @return mixed
	 */
	public static function PlainToHuman($value) {
		return $value;
	}
	
	/**
	 * Called when 
	 * @return void
	 */
	public function onUpdate($old) {}

}
