<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_Config_ParsedSettings
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description $parsedSettings emulator.
/**************************************************************************/

class Daemon_Config_ParsedSettings implements ArrayAccess {
  public function getRealOffsetName($offset) {
	  return str_replace('-','',strtolower($offset));
  }
	public function offsetExists($offset) {
	  $offset = $this->getRealOffsetName($offset);
	  if (substr($offset,0,3) == 'mod') {
			foreach ($this as $k => $v) {
				if ($v instanceof Daemon_ConfigSection) {
					if (strpos($offset,'mod'.$k) === 0) {
						return isset(Daemon::$settings->{$k}->{substr($offset,strlen($k)+3)}->value);
					}
				}
			}
			return FALSE;
	  }
		return isset(Daemon::$settings->{$offset}->value);
	}
	public function offsetGet($offset) {
		$offset = $this->getRealOffsetName($offset);
	  if (substr($offset,0,3) == 'mod') {
			foreach ($this as $k => $v) {
				if ($v instanceof Daemon_ConfigSection) {
					if (strpos($offset,'mod'.$k) === 0) {
						return Daemon::$settings->{$k}->{substr($offset,strlen($k)+3)}->value;
					}
				}
			}
			return NULL;
	  }
		return Daemon::$settings->{$offset}->value;
	}
	public function offsetSet($offset,$value) {
			$offset = $this->getRealOffsetName($offset);
	  if (substr($offset,0,3) == 'mod') {
			foreach ($this as $k => $v) {
				if ($v instanceof Daemon_ConfigSection) {
					if (strpos($offset,'mod'.$k) === 0) {
						Daemon::$settings->{$k}->{substr($offset,strlen($k)+3)}->value = $value;
					}
				}
			}
			return;
	  }
	  if (is_object(Daemon::$settings->{$offset})) {Daemon::$settings->{$offset}->value = $value;}
	  else {Daemon::$settings->{$offset} = $value;}
	}
	public function offsetUnset($offset) {
		unset(Daemon::$settings->{$this->getRealOffsetName($offset)});
	}
}
	
