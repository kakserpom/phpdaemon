<?php
/**************************************************************************/
/* phpDaemon
/* ver. 0.2
/* License: LGPL
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class AsyncProcess
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description AsyncProcess class
/**************************************************************************/
class AsyncProcess extends AsyncStream
{
 public $cmd;
 public $binPath;
 public $pipes;
 public $pd;
 public $run = FALSE;
 public $outputErrors = FALSE;
 public $setUser;
 public $setGroup;
 public $chroot = '/';
 public $env = array();
 public $cwd;
 public $errlogfile = '/tmp/cgi-errorlog.log';
 public $args;
 public $nice;
 public function __construct($cmd = NULL)
 {
  $this->base = Daemon::$worker->eventBase;
  $this->env = $_ENV;
  $this->cmd = $cmd;
 }
 public function setArgs($args = NULL)
 {
  $this->args = $args;
  return $this;
 }
 public function setEnv($env = NULL)
 {
  $this->env = $env;
  return $this;
 }
 public function nice($nice = NULL)
 {
  $this->nice = $nice;
  return $this;
 }
 public function execute($binPath = NULL,$args = NULL,$env = NULL)
 {
  if ($binPath !== NULL) {$this->binPath = $binPath;}
  if ($env !== NULL) {$this->env = $env;}
  if ($args !== NULL) {$this->args = $args;}
  $args = '';
  if ($this->args !== NULL)
  {
   foreach ($this->args as $a) {$args .= ' '.escapeshellcmd($a);}
  }
  $this->cmd = $this->binPath.$args.($this->outputErrors?' 2>&1':'');
  if (isset($this->setUser) || isset($this->setGroup))
  {
   if (isset($this->setUser) && isset($this->setGroup) && ($this->setUser !== $this->setGroup))
   {
    $this->cmd = 'sudo -g '.escapeshellarg($this->setGroup).' -u '.escapeshellarg($this->setUser).' '.$this->cmd;
   }
   else
   {
    $this->cmd = 'su '.escapeshellarg($this->setGroup).' -c '.escapeshellarg($this->cmd);
   }
  }
  if ($this->chroot !== '/')
  {
   $this->cmd = 'chroot '.escapeshellarg($this->chroot).' '.$this->cmd;
  }
  if ($this->nice !== NULL)
  {
   $this->cmd = 'nice -n '.((int) $this->nice).' '.$this->cmd;
  }
  $pipesDescr = array(
   0 => array('pipe','r'),  // stdin is a pipe that the child will read from
   1 => array('pipe','w'),  // stdout is a pipe that the child will write to
  );
  if (($this->errlogfile !== NULL) && !$this->outputErrors) {$pipesDescr[2] = array('file',$this->errlogfile,'a');}
  $this->pd = proc_open($this->cmd,$pipesDescr,$this->pipes,$this->cwd,$this->env);
  if ($this->pd)
  {
   $this->setFD($this->pipes[1],$this->pipes[0]);
   $this->enable();
  }
  return $this;
 }
 public function close()
 {
  $this->closeRead();
  $this->closeWrite();
  if ($this->pd) {proc_close($this->pd);}
 }
 public function eof()
 {
  if (!$this->EOF)
  {
   $stat = proc_get_status($this->pd);
   if (!$stat || ($stat['running'] == FALSE))
   {
    $this->onEofEvent();
   }
  }
  return $this->EOF;
 }
}
