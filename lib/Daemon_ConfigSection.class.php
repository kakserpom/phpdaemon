<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_Config
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Config class.
/**************************************************************************/

interface Interface_Daemon_ConfigSection extends ArrayAccess, Countable {}
class Daemon_ConfigSection implements Interface_Daemon_ConfigSection {
	public $source;
	public $revision;

	public function count()
	{
	 $c = 0;
	 foreach ($this as $prop) {++$c;}
	 return $c;
	}
	public function getRealOffsetName($offset) {
		return str_replace('-', '', strtolower($offset));
	}

	public function offsetExists($offset) {
		return $this->offsetGet($offset) !== NULL;
	}

	public function offsetGet($offset) {;
		return $this->{$this->getRealOffsetName($offset)}->value;
	}

	public function offsetSet($offset,$value) {
		$this->{$this->getRealOffsetName($offset)} = $value;
	}

	public function offsetUnset($offset) {
		unset($this->{$this->getRealOffsetName($offset)});
	}

}
