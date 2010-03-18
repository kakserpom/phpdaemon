<?php
/**************************************************************************/
/* phpDaemon
/* ver. 0.2
/* License: LGPL
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class SocketSession
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description SocketSession class.
/*************************************************************************/
class SocketSession
{
 public $buf = '';
 public $connId;
 public $appInstance;
 public $state = 0;
 public $auth = FALSE;
 public $finished = FALSE;
 public $addr;
 public function __construct($connId,$appInstance)
 {
  $this->connId = $connId;
  $this->appInstance = $appInstance;
  $this->init();
 }
 public function init()
 {
 }
 public function gets()
 {
  $p = strpos($this->buf,"\n");
  if ($p === FALSE) {return FALSE;}
  $r = binarySubstr($this->buf,0,$p+1);
  $this->buf = binarySubstr($this->buf,$p+1);
  return $r;
 }
 public function gracefulShutdown()
 {
  $this->finish();
  return TRUE;
 }
 public function write($s)
 {
  return $this->appInstance->write($this->connId,$s);
 }
 public function writeln($s)
 {
  return $this->appInstance->write($this->connId,$s."\n");
 }
 public function finish()
 {
  if ($this->finished) {return;}
  $this->finished = TRUE;
  $this->onFinish();
  $this->appInstance->finishConnection($this->connId);
 }
 public function onFinish()
 {
  $this->finished = TRUE;
  unset($this->appInstance->sessions[$this->connId]);
 }
 public function stdin($buf)
 {
  $this->buf .= $buf;
 }
}
