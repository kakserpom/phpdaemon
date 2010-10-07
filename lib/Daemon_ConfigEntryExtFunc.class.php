<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_ConfigEntryExtFunc
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description ConfigEntryExtFunc
/**************************************************************************/

class Daemon_ConfigEntryExtFunc extends Daemon_ConfigEntry {

	public function HumanToPlain($value)
	{
		$cb = include($value);

		return is_callable($cb) ? $cb : NULL;
	}

}
