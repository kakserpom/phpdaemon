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
            'WebSocketServer','SocksServer',
            'PostgreSQLProxy');
 public function __construct()
 {
  $this->appDir = array(
   Daemon::$dir.'/applications/',
   Daemon::$dir.'/app-servers/',
   Daemon::$dir.'/app-clients/',
   Daemon::$dir.'/app-web/',
   Daemon::$dir.'/app-examples/', // you can comment this.
  );
  foreach ($this->appDir as $dir)
  {
   $files = glob($dir.'*.php');
   foreach ($files as &$fn)
   {
    $p = pathinfo($fn,PATHINFO_FILENAME);
    if (!isset($this->appPreload[$p])) {$this->appPreload[$p] = 1;}
   }
  }
 }
}
