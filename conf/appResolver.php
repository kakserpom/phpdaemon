<?php

return new MyAppResolver;

class MyAppResolver extends AppResolver
{

    public $defaultApp = 'Example';
    public $appDir;
    public $appPreload = array(); // [appName1 => numberOfInstances1], ...
    public $appPreloadPrivileged = array(
        'FastCGI', 'HTTP', 'DebugConsole',
        'TelnetHoneypot', 'FlashPolicy',
        'RTEP', 'LockServer', 'MySQLProxy',
        'WebSocketServer', 'SocksServer',
        'PostgreSQLProxy');
    /* @method getRequestRoute
      @param object Request.
      @param object AppInstance of Upstream.
      @description Routes incoming request to related application. Method is for overloading.
      @return string Application's name.
     */

    public function getRequestRoute($req, $upstream)
    {
        if (preg_match('~^/(WebSocketOverCOMET|Example)/~', $req->attrs->server['DOCUMENT_URI'], $m)) {
            return $m[1];
        }

        // Example
        /* $host = basename($req->attrs->server['HTTP_HOST']);
          if (is_dir('/home/web/domains/'.basename($host)))
          {
          preg_match('~^/(.*)$~',$req->attrs->server['DOCUMENT_URI'],$m);
          $req->attrs->server['FR_URL'] = 'file:///home/web/domains/'.$host.'/'.$m[1];
          $req->attrs->server['FR_AUTOINDEX'] = TRUE;
          return 'FileReader';
          } */
    }

    public function __construct()
    {
        $this->appDir = array(
            Daemon::$dir . '/app-servers/',
            Daemon::$dir . '/app-clients/',
            Daemon::$dir . '/app-web/',
            Daemon::$dir . '/app-examples/', // you can comment this.
            Daemon::$dir . '/applications/',
        );
        foreach ($this->appDir as $dir) {
            $files = glob($dir . '*.php');
            foreach ($files as &$fn) {
                $p = pathinfo($fn, PATHINFO_FILENAME);
                if (!isset($this->appPreload[$p])) {
                    $this->appPreload[$p] = 1;
                }
            }
        }
    }

}
