<?php
return new LockClient;
class LockClient extends AsyncServer
{
 public $sessions = array();
 public $servers = array();
 public $servConn = array();
 public $prefix = '';
 public $jobs = array();
 public $dtags_enabled = FALSE;
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'servers' => '127.0.0.1',
   'mod'.$this->modname.'port' => 833,
   'mod'.$this->modname.'prefix' => '',
   'mod'.$this->modname.'enable' => 0,
  ));
  $this->prefix = &Daemon::$settings['mod'.$this->modname.'prefix'];
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $servers = explode(',',Daemon::$settings['mod'.$this->modname.'servers']);
   foreach ($servers as $s)
   {
    $e = explode(':',$s);
    $this->addServer($e[0],isset($e[1])?$e[1]:NULL);
   }
  }
 }
 public function addServer($host,$port = NULL,$weight = NULL)
 {
  if ($port === NULL) {$port = Daemon::$settings['mod'.$this->modname.'port'];}
  $this->servers[$host.':'.$port] = $weight;
 }
 public function job($name,$wait,$onRun,$onSuccess = NULL,$onFailure = NULL)
 {
  $name = $this->prefix.$name;
  $connId = $this->getConnectionByName($name);
  if (!isset($this->sessions[$connId])) {return;}
  $sess = $this->sessions[$connId];
  $this->jobs[$name] = array($onRun,$onSuccess,$onFailure);
  $sess->writeln('acquire'.($wait?'Wait':'').' '.$name);
 }
 public function done($name)
 {
  $connId = $this->getConnectionByName($name);
  $sess = $this->sessions[$connId];
  $sess->writeln('done '.$name);
 }
 public function failed($name)
 {
  $connId = $this->getConnectionByName($name);
  $sess = $this->sessions[$connId];
  $sess->writeln('failed '.$name);
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
  }
 }
 public function getConnection($addr)
 {
  if (isset($this->servConn[$addr]))
  {
   foreach ($this->servConn[$addr] as &$c)
   {
    return $c;
   }
  }
  else {$this->servConn[$addr] = array();}
  $e = explode(':',$addr);
  $connId = $this->connectTo($e[0],$e[1]);
  $this->sessions[$connId] = new LockClientSession($connId,$this);
  $this->servConn[$addr][] = $connId;
  return $connId;
 }
 private function getConnectionByName($name)
 {
  if (($this->dtags_enabled) && (($sp = strpos($name,'[')) !== FALSE) && (($ep = strpos($name,']')) !== FALSE) && ($ep > $sp))
  {
   $name = substr($name,$sp+1,$ep-$sp-1);
  }
  srand(crc32($name));
  $addr = array_rand($this->servers);
  srand();  
  return $this->getConnection($addr);
 }
}
class LockClientSession extends SocketSession
{
 public function stdin($buf)
 {
  $this->buf .= $buf;
  while (($l = $this->gets()) !== FALSE)
  {
   $e = explode(' ',rtrim($l,"\r\n"));
   if ($e[0] === 'RUN')
   {
    if (isset($this->appInstance->jobs[$e[1]]))
    {
     call_user_func($this->appInstance->jobs[$e[1]][0],$e[0],$e[1],$this->appInstance);
    }
   }
   elseif ($e[0] === 'DONE')
   {
    if (isset($this->appInstance->jobs[$e[1]][1]))
    {
     call_user_func($this->appInstance->jobs[$e[1]][1],$e[0],$e[1],$this->appInstance);
    }
   }
   elseif ($e[0] === 'FAILED')
   {
    if (isset($this->appInstance->jobs[$e[1]][2]))
    {
     call_user_func($this->appInstance->jobs[$e[1]][2],$e[0],$e[1],$this->appInstance);
    }
   }
  }
 }
}
