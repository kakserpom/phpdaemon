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

	public function __construct() {
		if (func_num_args() == 1) {
			$this->setDefaultValue(func_get_arg(0));
			$this->setHumanValue(func_get_arg(0));
		}
	}

	public function setValue($value) {
		$old = $this->value;
		$this->value = $value;
		$this->humanValue = $this->PlainToHuman($value);
		$this->onUpdate($old);
	}

	public function setValueType($type) {
		$this->valueType = $type;
	}

	public function resetToDefault() {
		if ($this->hasDefaultValue) {
			$this->setHumanValue($this->defaultValue);

			return true;
		}

		return false;
	}
 
	public function setDefaultValue($value) {
		$this->defaultValue = $value;
		$this->hasDefaultValue = true;
	}

	public function setHumanValue($value) {
		$this->humanValue = $value;
		$old = $this->value;
		$this->value = $this->HumanToPlain($value);
		$this->onUpdate($old);
	}
	public function HumanToPlain($value) {
		return $value;
	}

	public function PlainToHuman($value) {
		return $value;
	}
	
	public function onUpdate($old) {
	}

}
