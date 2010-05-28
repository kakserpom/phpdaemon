<?php
return new GearmanNode;
class GearmanNode extends AppInstance
{
 public $client;
 public $worker;
 public $interval;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'servers' => '127.0.0.1',
   'mod'.$this->modname.'port' => 4730,
   'mod'.$this->modname.'enable' => 0,
  ));
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'enable'])) {Daemon::$settings[$k] = 0;}
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   
   $this->client = new GearmanClient;
   $this->worker = new GearmanWorker;
   foreach (explode(',',Daemon::$settings['mod'.$this->modname.'servers']) as $address)
   {
    $e = explode(':',$address,2);
    $this->client->addServer($e[0],isset($e[1])?$e[1]:Daemon::$settings['mod'.$this->modname.'port']);
    $this->worker->addServer($e[0],isset($e[1])?$e[1]:Daemon::$settings['mod'.$this->modname.'port']);
   }
   $this->interval = $this->pushRequest(new GearmanNodeInterval($this,$this));
  }
 }
}
class GearmanNodeInterval extends Request
{
 /* @method run
    @description Called when request iterated.
    @return integer Status.
 */
 public function run()
 {
  $this->worker->work();
  $this->sleep(0.2);
 }
}
