<?php
return new FlashPolicy;
class FlashPolicy extends AsyncServer
{
 public $sessions = array();
 public $policyData;
 public function init()
 {
  Daemon::$settings += array(
   'mod'.$this->modname.'listen' => 'tcp://0.0.0.0',
   'mod'.$this->modname.'listenport' => 843,
   'mod'.$this->modname.'file' => Daemon::$dir.'/conf/crossdomain.xml',
   'mod'.$this->modname.'enable' => 0
  );
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->bindSockets(Daemon::$settings['mod'.$this->modname.'listen'],Daemon::$settings['mod'.$this->modname.'listenport']);
  }
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->policyData = file_get_contents(Daemon::$settings['mod'.$this->modname.'file']);
   $this->enableSocketEvents();
  }
 }
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new FlashPolicySession($connId,$this);
 }
}
class FlashPolicySession extends SocketSession
{
 public function stdin($buf)
 {
  $this->buf .= $buf;
  $finish = (strpos($this->buf,"\xff\xf4\xff\xfd\x06") !== FALSE) || (strpos($this->buf,"\xff\xec") !== FALSE);
  if (strpos($this->buf,'<policy-file-request/>') !== FALSE)
  {
   if ($this->appInstance->policyData) {$this->write($this->appInstance->policyData."\x00");}
   else {$this->write("<error/>\x00");}
   $this->finish();
  }
  elseif ($finish) {$this->finish();}
 }
}
