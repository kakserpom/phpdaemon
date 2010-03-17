<?php
return new MyAppResolver;
class MyAppResolver extends AppResolver
{
 public $defaultApp = 'Example';
 public $appDir;
 public $appPreload = array(); // [appName1 => numberOfInstances1], ...
 public $appPreloadPrivileged = array(
            'FastCGI','HTTP','DebugConsole',
            'TelnetHoneypot','FlashPolicy',
            'RTEP','LockServer','MySQLProxy',
            'WebSocketServer');
 public function __construct()
 {
  $this->appDir = Daemon::$dir.'/applications/';
  $files = glob($this->appDir.'*.php');
  foreach ($files as &$fn)
  {
   $p = pathinfo($fn,PATHINFO_FILENAME);
   if (!isset($this->appPreload[$p])) {$this->appPreload[$p] = 1;}
  }
 }
}
