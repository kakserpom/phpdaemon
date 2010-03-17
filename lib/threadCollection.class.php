<?php
class ThreadCollection
{
 public $threads = array();
 public $waitstatus;
 public $spawncounter = 0;
 public function push($thread)
 {
  ++$this->spawncounter;
  $thread->spawnid = $this->spawncounter;
  $this->threads[$thread->spawnid] = $thread;
 }
 public function start()
 {
  foreach ($this->threads as $thread)
  {
   $thread->start();
  }
 }
 public function stop($kill = FALSE)
 {
  foreach ($this->threads as $thread)
  {
   $thread->stop($kill);
  }
 }
 public function getNumber()
 {
  return sizeof($this->threads);
 }
 public function removeTerminated($check = FALSE)
 {
  $n = 0;
  foreach ($this->threads as $k => &$t)
  {
   if ($t->terminated) {unset($this->threads[$k]);}
   elseif ($check && (!$thread->signal(SIGTTIN))) {unset($this->threads[$k]);}
   else {++$n;}
  }
  return $n;
 }
 public function signal($sig)
 {
  foreach ($this->threads as $thread)
  {
   $thread->signal($sig);
  }
 }
}
