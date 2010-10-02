<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_Config
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Config class.
/**************************************************************************/

class Daemon_ConfigSection implements ArrayAccess {

  public function getRealOffsetName($offset) {
	  return str_replace('-','',strtolower($offset));
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
