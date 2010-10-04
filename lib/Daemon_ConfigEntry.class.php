<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_ConfigEntry
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Config entry class.
/**************************************************************************/

class Daemon_ConfigEntry {

	public $defaultValue;
	public $value;
	public $humanValue;
	public $source;
	public $revision;
	public $hasDefaultValue = FALSE;

	public function __construct() {
		if (func_num_args() == 1)	{
			$this->setDefaultValue(func_get_arg(0));
			$this->setHumanValue(func_get_arg(0));
		}
	}

	public function setValue($value) {
		$this->value = $value;
		$this->humanValue = $this->PlainToHuman($value);
	}

	public function setValueType($type) {
		$this->valueType = $type;
	}

  public function resetToDefault()
  {
		if ($this->hasDefaultValue)	{
			$this->setHumanValue($this->defaultValue);
			return TRUE;
		}
		return FALSE;
  }
 
	public function setDefaultValue($value) {
		$this->defaultValue = $value;
		$this->hasDefaultValue = TRUE;
	}

	public function setHumanValue($value) {
		$this->humanValue = $value;
		$this->value = $this->HumanToPlain($value);
	}

	public function HumanToPlain($value) {
		return $value;
	}

	public function PlainToHuman($value) {
		return $value;
	}

}
