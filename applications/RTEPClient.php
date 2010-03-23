<?php
return new RTEPClient;
class RTEPClient extends AppInstance
{
 public $client;
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'listen' => 'tcpstream://127.0.0.1:844',
   'mod'.$this->modname.'enable' => 0,
  ));
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'addr'])) {Daemon::$settings[$k] = 'tcpstream://127.0.0.1:844';}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'enable'])) {Daemon::$settings[$k] = 0;}
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   require_once Daemon::$dir.'/lib/asyncRTEPclient.class.php';
   $this->client = new AsyncRTEPclient;
   $this->client->addServer(Daemon::$settings[$k = 'mod'.$this->modname.'addr']);
   $this->client->trace = TRUE;
  }
 }
}
