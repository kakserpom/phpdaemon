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
	public function __construct($defaultValue = NULL)
	{
		if ($defaultValue !== NULL){
			$this->setDefaultValue($defaultValue);
			$this->setHumanValue($defaultValue);
		}
	}
	public function setValue($value)
	{
	 $this->value = $value;
	 $this->humanValue = $this->PlainToHuman($value);
	}
	public function setValueType($type)
	{
	 $this->valueType = $type;
	}
	public function setDefaultValue($value)
	{
	 $this->defaultValue = $value;
	}
	public function setHumanValue($value)
	{
	 $this->humanValue = $value;
	 $this->value = $this->HumanToPlain($value);
	}
	public function HumanToPlain($value)
	{
	 return $value;
	}
	public function PlainToHuman($value)
	{
	 return $value;
	}
}
