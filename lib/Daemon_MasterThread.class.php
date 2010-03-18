<?php
/**************************************************************************/
/* phpDaemon
/* ver. 0.2
/* License: LGPL
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_MasterThread
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Implementation of the master thread.
/**************************************************************************/
class Daemon_MasterThread extends Thread
{
 /* @method run
    @description runtime of Master process
    @return void
 */
 public function run()
 {
  proc_nice(Daemon::$settings['masterpriority']);
  gc_enable();
  register_shutdown_function(array($this,'onShutdown'));
  $this->collections = array('workers' => new threadCollection);
  Thread::setproctitle(Daemon::$runName.': master process'.(Daemon::$settings['pidfile'] !== Daemon::$settings['defaultpidfile']?' ('.Daemon::$settings['pidfile'].')':''));
  
  Daemon::$appResolver = require Daemon::$settings['path'];
  Daemon::$appResolver->preloadPrivileged(); 
  
  $this->spawnWorkers(min(Daemon::$settings['startworkers'],Daemon::$settings['maxworkers']));
  $mpmLast = time();
  $autoReloadLast = time();
  while (TRUE)
  {
   pcntl_signal_dispatch();
   $this->sigwait(1,0);
   clearstatcache();
   if (Daemon::$logpointerpath !== Daemon::parseStoragepath(Daemon::$settings['logstorage']))
   { 
    $this->sigusr1();
   }
   $c = 1;
   if (time() > $mpmLast+Daemon::$parsedSettings['mpmdelay'])
   {
    $mpmLast = time();
    ++$c;
    if ($c > 0xFFFFF) {$c = 0;}
    if (($c % 10 == 0))
    {
     $this->collections['workers']->removeTerminated(TRUE);
     gc_collect_cycles();
    }
    else {$this->collections['workers']->removeTerminated();}
    if (isset(Daemon::$settings['mpm']) && is_callable($c = Daemon::$settings['mpm']))
    {
     call_user_func($c);
    }
    else // default MPM
    {
     $state = Daemon::getStateOfWorkers($this);
     if ($state)
     {
      $n = max(
       min(Daemon::$settings['minspareworkers'] - $state['idle'],Daemon::$settings['maxworkers'] - $state['alive']),
		   Daemon::$settings['minworkers'] - $state['alive']
		  );
      if ($n > 0)
      {
       Daemon::log('Spawning '.$n.' worker(s).');
       $this->spawnWorkers($n);
      }
      $n = min($state['idle'] - Daemon::$settings['maxspareworkers'],$state['alive'] - Daemon::$settings['minworkers']);
      if ($n > 0)
      {
       Daemon::log('Stopping '.$n.' worker(s).');
       $this->stopWorkers($n);
      }
     }
    }
   }
  }
 }
 public function reloadWorker($spawnId)
 {
  if (isset($this->collections['workers']->threads[$spawnId]))
	{
	 if (!$this->collections['workers']->threads[$spawnId]->reloaded)
	 {
	  Daemon::log('Spawning worker-replacer for reloaded worker #'.$spawnId.'.');
	  $this->spawnWorkers();
	  $this->collections['workers']->threads[$spawnId]->reloaded = TRUE;
	 }
  }
 }
 /* @method spawnWorkers
    @param $n - integer - number of workers to spawn
    @description spawn new workers processes.
    @return boolean - success
 */
 public function spawnWorkers($n = 1)
 {
  $n = (int) $n;
  for ($i = 0; $i < $n; ++$i)
  {
   $thread = new Daemon_WorkerThread;
   $this->collections['workers']->push($thread);
   if (-1 === $thread->start())
   {
    Daemon::log('could not start worker');
   }
  }
  return TRUE;
 }
 /* @method stopWorkers
    @param $n - integer - number of workers to stop
    @description stop the workers.
    @return boolean - success
 */
 public function stopWorkers($n = 1)
 {
  $n = (int) $n;
  $i = 0;
  foreach ($this->collections['workers']->threads as &$w)
  {
   if ($i >= $n) {break;}
   if ($w->shutdown) {continue;}
   $w->stop();
   ++$i;
  }
  return TRUE;
 }
 public function onShutdown()
 {
  if ($this->pid != posix_getpid()) {return;}
  if ($this->shutdown === TRUE) {return;}
  Daemon::log('Unexcepted master shutdown.'); 
  $this->shutdown(SIGTERM);
 }
 public function shutdown($signo = FALSE)
 {
  $this->shutdown = TRUE;
  $this->waitAll($signo);
  if (Daemon::$shm_wstate) {shmop_delete(Daemon::$shm_wstate);}
  file_put_contents(Daemon_Bootstrap::$pidfile,'');
  exit(0);
 }
 public function sigchld()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGCHLD.');}
  $this->waitPid();
 }
 public function sigint()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGINT.');}
  $this->collections['workers']->signal(SIGINT);
  $this->shutdown(SIGINT);
 }
 public function sigterm()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGTERM.');}
  $this->collections['workers']->signal(SIGTERM);
  $this->shutdown(SIGTERM);
 }
 public function sigquit()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGQUIT.');}
  $this->collections['workers']->signal(SIGQUIT);
  $this->shutdown(SIGQUIT);
 }
 public function sighup()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGHUP (reload config).');}
  if (isset(Daemon::$settings['configfile'])) {Daemon::loadConfig(Daemon::$settings['configfile']);}
  $this->collections['workers']->signal(SIGHUP);
 }
 public function sigusr1()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGUSR1 (re-open log-file).');}
  Daemon::openLogs();
  $this->collections['workers']->signal(SIGUSR1);
 }
 public function sigusr2()
 {
  if (Daemon::$settings['logsignals']) {Daemon::log('Master caught SIGUSR2 (graceful restart all workers).');}
  $this->collections['workers']->signal(SIGUSR2);
 }
 public function sigttin()
 {
 }
 public function sigxfsz()
 {
  Daemon::log('Master caught SIGXFSZ (graceful restart all workers).');
 }
 public function sigunknown($signo)
 {
  if (isset(Thread::$signals[$signo])) {$sig = Thread::$signals[$signo];}
  else {$sig = 'UNKNOWN';}
  Daemon::log('Master caught signal #'.$signo.' ('.$sig.').');
 }
}
