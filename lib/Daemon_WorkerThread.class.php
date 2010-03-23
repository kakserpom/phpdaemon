<?php
/**************************************************************************/
/* phpDaemon
/* ver. 0.2
/* License: LGPL
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_WorkerThread
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Implementation of the worker thread.
/**************************************************************************/
class Daemon_WorkerThread extends Thread
{
 public $update = FALSE;
 public $reload = FALSE;
 public $reloadTime = 0;
 public $reloadDelay = 2;
 public $reloaded = FALSE;
 public $pool = array();
 public $poolApp = array();
 public $connCounter = 0;
 public $queryCounter = 0;
 public $queue = array();
 public $timeLastReq = 0;
 public $readPoolState = array();
 public $writePoolState = array();
 public $autoReloadLast = 0;
 public $currentStatus = 0;
 public $microsleep;
 public $eventsToAdd = array();
 public $eventBase;
 public $timeoutEvent;
 public $useSockets;
 public $status = 0;
 public function run()
 {
  proc_nice(Daemon::$settings['workerpriority']);
  Daemon::$worker = $this;
  $this->microsleep = Daemon::$settings['microsleep'];
  $this->autoReloadLast = time();
  $this->reloadDelay = Daemon::$parsedSettings['mpmdelay']+2;
  $this->setStatus(4);
  Thread::setproctitle(Daemon::$runName.': worker process'.(Daemon::$settings['pidfile'] !== Daemon::$settings['defaultpidfile']?' ('.Daemon::$settings['pidfile'].')':''));
  register_shutdown_function(array($this,'shutdown'));
  if (Daemon::$settings['autogc'] > 0) {gc_enable();}
  else {gc_disable();}
  if (isset(Daemon::$settings['group']))
  {
   $sg = posix_getgrnam(Daemon::$settings['group']);
  }
  if (isset(Daemon::$settings['user']))
  {
   $su = posix_getpwnam(Daemon::$settings['user']);
  }
  if (Daemon::$settings['chroot'] !== '/')
  {
   if (posix_getuid() != 0)
   {
    Daemon::log('You must have the root privileges to change root.');
    exit(0);
   }
   elseif (!chroot(Daemon::$settings['chroot']))
   {
    Daemon::log('Couldn\'t change root to \''.Daemon::$settings['chroot'].'\'.');
    exit(0);
   }
  }
  if (isset(Daemon::$settings['group']))
  {
   if ($sg === FALSE)
   {
    Daemon::log('Couldn\'t change group to \''.Daemon::$settings['group'].'\'. You must replace config-variable \'group\' with existing group.');
    exit(0);
   }
   elseif (($sg['gid'] != posix_getgid()) && (!posix_setgid($sg['gid'])))
   {
    Daemon::log('Couldn\'t change group to \''.Daemon::$settings['group']."'. Error (".($errno = posix_get_last_error()).'): '.posix_strerror($errno));
    exit(0);
   }
  }
  if (isset(Daemon::$settings['user']))
  {
   if ($su === FALSE)
   {
    Daemon::log('Couldn\'t change user to \''.Daemon::$settings['user'].'\', user not found. You must replace config-variable \'user\' with existing username.');
    exit(0);
   }
   elseif (($su['uid'] != posix_getuid()) && (!posix_setuid($su['uid'])))
   {
    Daemon::log('Couldn\'t change user to \''.Daemon::$settings['user']."'. Error (".($errno = posix_get_last_error()).'): '.posix_strerror($errno));
    exit(0);
   }
  }
  if (Daemon::$settings['cwd'] !== '.')
  {
   if (!@chdir(Daemon::$settings['cwd']))
   {
    Daemon::log('WORKER '.$this->pid.'] Couldn\'t change directory to \''.Daemon::$settings['cwd'].'.');
   }
  }
  $this->setStatus(6);
  $this->eventBase = event_base_new();
  Daemon::$appResolver->preload();
  foreach (Daemon::$appInstances as $app)
  {
   foreach ($app as $appInstance)
   {
    if (!$appInstance->ready)
    {
     $this->ready = TRUE;
     $appInstance->onReady();
    }
   }
  }
  $this->setStatus(1);

  $ev = event_new();
  event_set($ev, STDIN, EV_TIMEOUT, function() {}, array());
  event_base_set($ev, $this->eventBase);
  $this->timeoutEvent = $ev;

  while (TRUE)
  {
   pcntl_signal_dispatch();
   if (($s = $this->checkState()) !== TRUE)
   {
    $this->closeSockets();
    if (sizeof($this->queue) === 0) {return $s;}
   }
   event_add($this->timeoutEvent, $this->microsleep);
   event_base_loop($this->eventBase, EVLOOP_ONCE);
   do
   {
    for ($i = 0, $s = sizeof($this->eventsToAdd); $i < $s; ++$i)
    {
     event_add($this->eventsToAdd[$i]);
     unset($this->eventsToAdd[$i]);
    }
    $this->readPool();
    $processed = $this->runQueue();
   }
   while ($processed || $this->readPoolState || $this->eventsToAdd);
  }
 }
 public static function init()
 {
 }
 public function closeSockets()
 {
  foreach (Daemon::$socketEvents as $k => $ev)
  {
   event_del($ev);
   event_free($ev);
   unset($this->socketEvents[$k]);
  }
  foreach (Daemon::$sockets as $k => &$s)
  {
   if (Daemon::$useSockets) {socket_close($s[0]);}
   else {fclose($s[0]);}
   unset(Daemon::$sockets[$k]);
  }
 }
 public function update()
 {
 }
 public function addEvent($e)
 {
  $this->eventsToAdd[sizeof($this->eventsToAdd)] = $e;
  return TRUE;
 }
 public function reloadCheck()
 {
  static $hash = array();
  $this->autoReloadLast = time();
  $inc = get_included_files();
  foreach ($inc as &$path)
  {
   $mt = filemtime($path);
   if (isset($hash[$path]) && ($mt > $hash[$path]))
   {
    return TRUE;
   }
   $hash[$path] = $mt;
  }
  return FALSE;
 }
 public function checkState()
 {
  pcntl_signal_dispatch();
  if ($this->terminated) {return FALSE;} 
  if ((Daemon::$parsedSettings['autoreload'] > 0) && (time() > $this->autoReloadLast+Daemon::$parsedSettings['autoreload']))
  {
   if ($this->reloadCheck())
   {
    $this->reload = TRUE;
    $this->setStatus($this->currentStatus);
   }
  }
  if ($this->status > 0) {return $this->status;}
  if (Daemon::$settings['maxrequests'] && ($this->queryCounter >= Daemon::$settings['maxrequests']))
  {
   Daemon::log('[WORKER '.$this->pid.'] \'maxrequests\' exceed. Graceful shutdown.');
   $this->status = 3;
  }
  if ((Daemon::$parsedSettings['maxmemoryusage'] > 0) && (memory_get_usage(TRUE) > Daemon::$parsedSettings['maxmemoryusage']))
  {
   Daemon::log('[WORKER '.$this->pid.'] \'maxmemoryusage\' exceed. Graceful shutdown.');
   $this->status = 3;
  }
  if (Daemon::$parsedSettings['maxidle'] && $this->timeLastReq && (time()-$this->timeLastReq > Daemon::$parsedSettings['maxidle']))
  {
   Daemon::log('[WORKER '.$this->pid.'] \'maxworkeridle\' exceed. Graceful shutdown.');
   $this->status = 3;
  }
  if ($this->update === TRUE)
  {
   $this->update = FALSE;
   $this->update();
  }
  if ($this->shutdown === TRUE)
  {
   $this->status = 5;
  }
  if (($this->reload === TRUE) && (microtime(TRUE) > $this->reloadTime))
  {
   $this->status = 6;
  }
  if ($this->status > 0)
  {
   foreach (Daemon::$appInstances as $app)
   {
    foreach ($app as $appInstance) {$appInstance->handleStatus($this->status);}
   }
   return $this->status;
  }
  return TRUE;
 }
 public function runQueue()
 {
  $processed = 0;
  foreach ($this->queue as $k => &$r)
  {
   if (!$r instanceof stdClass)
   {
    if ($r->state === 3)
    {
     if (microtime(TRUE) > $r->sleepuntil) {$r->state = 1;}
     else {continue;}
    }
    if (Daemon::$settings['logqueue']) {Daemon::log('[WORKER '.$this->pid.'] event runQueue(): ('.$k.') -> '.get_class($r).'::call() invoked.');}
    $ret = $r->call();
    if (Daemon::$settings['logqueue']) {Daemon::log('[WORKER '.$this->pid.'] event runQueue(): ('.$k.') -> '.get_class($r).'::call() returned '.$ret.'.');}
    if ($ret === 1)
    {
     $processed++;
     unset($this->queue[$k]);
     if (isset($r->idAppQueue))
     {
      if (Daemon::$settings['logqueue']) {Daemon::log('[WORKER '.$this->pid.'] request removed from '.get_class($r->appInstance).'->queue.');}
      unset($r->appInstance->queue[$r->idAppQueue]);
     }
     else
     {
      if (Daemon::$settings['logqueue']) {Daemon::log('[WORKER '.$this->pid.'] request can\'t be removed from AppInstance->queue.');}
     }
    }
   }
  }
  return $processed;
 }
 public function readPool()
 {
  foreach ($this->readPoolState as $connId => $state)
  {
   if (Daemon::$settings['logevents']) {Daemon::log('[WORKER '.$this->pid.'] event readConn('.$connId.') invoked.');}
   $this->poolApp[$connId]->readConn($connId);
   if (Daemon::$settings['logevents']) {Daemon::log('[WORKER '.$this->pid.'] event readConn('.$connId.') finished.');}
  }
 }
 public function appInstancesReloadReady()
 {
  $ready = TRUE;
  foreach (Daemon::$appInstances as $k => $app)
  {
   foreach ($app as $appInstance)
   {
    if (!$appInstance->handleStatus($this->currentStatus)) {$ready = FALSE;}
   }
  }
  return $ready;
 }
 public function shutdown($hard = FALSE)
 {
  if (Daemon::$settings['logevents']) {Daemon::log('[WORKER '.$this->pid.'] event shutdown('.($hard?'HARD':'').') invoked.');}
  if (Daemon::$settings['throwexceptiononshutdown']) {throw new Exception('event shutdown');}
  @ob_flush();
  if ($this->terminated === TRUE)
  {
   if ($hard) {exit(0);}
   return;
  }
  $this->terminated = TRUE;
  $this->closeSockets();
  $this->setStatus(3);
  if ($hard) {exit(0);}
  $reloadReady = $this->appInstancesReloadReady();
  foreach ($this->queue as $r)
  {
   if ($r instanceof stdClass) {continue;}
   if ($r->running) {$r->finish(-2);}
  }
  $n = 0;
  while ((sizeof($this->queue) > 0) || !$reloadReady)
  {
   if ($n++ === 100)
   {
    $reloadReady = $this->appInstancesReloadReady();
    $n = 0;
   }
   pcntl_signal_dispatch();
   event_add($this->timeoutEvent, $this->microsleep);
   event_base_loop($this->eventBase,EVLOOP_ONCE);
   $this->readPool();
   $this->runQueue();
  }
  posix_kill(posix_getppid(),SIGCHLD);
  exit(0);
 }
 public function setStatus($int)
 {
  if (!$this->spawnid) {return FALSE;}
  $this->currentStatus = $int;
  if ($this->reload) {$int += 100;}
  if (Daemon::$settings['logworkersetstatus']) {Daemon::log('[WORKER '.$this->pid.'] status is '.$int);}
  return shmop_write(Daemon::$shm_wstate,chr($int),$this->spawnid-1);
 }
 public function sigint()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGINT.');}
  $this->shutdown(TRUE);
 }
 public function sigterm()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGTERM.');}
  $this->shutdown();
 }
 public function sigquit()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGQUIT.');}
  $this->shutdown = TRUE;
 }
 public function sighup()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGHUP (reload config).');}
  if (isset(Daemon::$settings['configfile'])) {Daemon::loadConfig(Daemon::$settings['configfile']);}
  $this->update = TRUE;
 }
 public function sigusr1()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGUSR1 (re-open log-file).');}
  Daemon::openLogs();
 }
 public function sigusr2()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Worker '.getmypid().' caught SIGUSR2 (graceful shutdown for update).');}
  $this->reload = TRUE;
  $this->reloadTime = microtime(TRUE)+$this->reloadDelay;
  $this->setStatus($this->currentStatus);
 }
 public function sigttin()
 {
 }
}
