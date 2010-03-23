<?php
return new DebugConsole;
class DebugConsole extends AsyncServer
{
 public $sessions = array();
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'listen' => 'tcp://0.0.0.0',
   'mod'.$this->modname.'listenport' => 8818,
   'mod'.$this->modname.'passphrase' => 'secret',
   'mod'.$this->modname.'enable' => 0,
  ));
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->bindSockets(Daemon::$settings['mod'.$this->modname.'listen'],Daemon::$settings['mod'.$this->modname.'listenport']);
  }
 }
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new DebugConsoleSession($connId,$this);
 }
}
class DebugConsoleSession extends SocketSession
{
 public $state = 0;
 public $auth = FALSE;
 public function init()
 {
  $this->write("Welcome! DebugConsole for phpDaemon.\n\n");
 }
 public function stdin($buf)
 {
  $this->buf .= $buf;
  $finish = (strpos($this->buf,$s = "\xff\xf4\xff\xfd\x06") !== FALSE) || (strpos($this->buf,$s = "\xff\xec") !== FALSE)
            || (strpos($this->buf,$s = "\x03") !== FALSE) || (strpos($this->buf,$s = "\x04") !== FALSE);
  while (($line = $this->gets()) !== FALSE)
  {
   $e = explode(' ',rtrim($line,"\r\n"),2);
   $cmd = trim(strtolower($e[0]));
   $arg = isset($e[1])?$e[1]:'';
   if ($cmd === 'ping')
   {
    $this->writeln('pong');
   }
   elseif ($cmd === 'login')
   {
    if ($this->auth)
    {
     $this->writeln('You are authorized already.');
    }
    elseif ($arg === Daemon::$settings['mod'.$this->appInstance->modname.'passphrase'])
    {
     $this->auth = TRUE;
     $this->writeln('OK.');
    }
    else
    {
     $this->writeln('Incorrect passphrase.');
    }
   }
   elseif ($cmd === 'logout')
   {
    if (!$this->auth)
    {
     $this->writeln('You are not authorized.');
    }
    else
    {
     $this->auth = FALSE;
     $this->writeln('OK.');
    }
   }
   elseif ($cmd === 'eval')
   {
    if (!$this->auth) {$this->writeln('You must be authorized.');}
    else
    {
     ob_start();
     eval($arg);
     $out = ob_get_contents();
     ob_end_clean();
     $this->writeln($out);
    }
   }
   elseif ($cmd === 'help')
   {
    $this->writeln('DebugConsole for phpDaemon.
Commands:
1) help
2) login [password]
3) logout
4) eval');
   }
   elseif (($cmd === 'exit') || ($cmd === 'quit'))
   {
    $this->writeln('Quit');
    $this->finish();
   }
   else
   {
    $this->writeln('Unknown command "'.$cmd.'"');
   }
  }
  if ($finish) {$this->finish();}
 }
}
