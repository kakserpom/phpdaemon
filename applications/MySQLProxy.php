<?php
return new MySQLProxy;
class MySQLProxy extends AsyncServer
{
 public $sessions = array();
 public function init()
 {
  Daemon::$settings += array(
   'mod'.$this->modname.'upserver' => '127.0.0.1:3306',
   'mod'.$this->modname.'listen' => 'tcp://0.0.0.0',
   'mod'.$this->modname.'listenport' => 3307,
   'mod'.$this->modname.'protologging' => 0,
   'mod'.$this->modname.'enable' => 0,
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
   $this->enableSocketEvents();
  }
 }
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new MySQLProxySession($connId,$this);
  return TRUE;
 }
}
class MySQLProxySession extends SocketSession
{
 public $upstream;
 public function init()
 {
  $e = explode(':',Daemon::$settings[$k = 'mod'.$this->appInstance->modname.'upserver']);
  $connId = $this->appInstance->connectTo($e[0],$e[1]);
  $this->upstream = $this->appInstance->sessions[$connId] = new MySQLProxyUpserverSession($connId,$this->appInstance);
  $this->upstream->downstream = $this;
 }
 public function stdin($buf)
 {
  // from client to mysqld.
  if (Daemon::$settings[$k = 'mod'.$this->appInstance->modname.'protologging']) {Daemon::log('MySQLProxy: Client --> Server: '.Daemon::exportBytes($buf)."\n\n");}
  $this->upstream->write($buf);
 }
 public function onFinish()
 {
  $this->upstream->finish();
 }
}
class MySQLProxyUpserverSession extends SocketSession
{
 public $downstream;
 public function stdin($buf)
 {
  // from mysqld to client.
  if (Daemon::$settings[$k = 'mod'.$this->modname.'protologging']) {Daemon::log('MysqlProxy: Server --> Client: '.Daemon::exportBytes($buf)."\n\n");}
  $this->buf .= $buf;
  $this->downstream->write($buf);
 }
 public function onFinish()
 {
  $this->downstream->finish();
 }
}
