<?php

/**
 * Default application resolver
 *
 * @package Core
 * @author  Zorin Vasily <maintainer@daemon.io>
 */


use PHPDaemon\Core\Daemon;

class MyAppResolver extends \PHPDaemon\Core\AppResolver{


    /**
     * Routes incoming request to related application. Method is for overloading.
     * @param object Request.
     * @param object AppInstance of Upstream.
     * @return string Application's name.
     */
    public function getRequestRoute($req, $upstream){

        /*
            This method should return application name to handle incoming request ($req).
        */

        if(strpos($req->attrs->server['DOCUMENT_URI'], 'GibsonTest'))
            return 'GibsonTest';

        /*  $route = pathinfo($req->attrs->server['DOCUMENT_URI']);
          return $route['filename'];*/


        /* Example
        $host = basename($req->attrs->server['HTTP_HOST']);

        if (is_dir('/home/web/domains/' . basename($host))) {
            preg_match('~^/(.*)$~', $req->attrs->server['DOCUMENT_URI'], $m);
            $req->attrs->server['FR_PATH'] = '/home/web/domains/'.$host.'/'.$m[1];
            $req->attrs->server['FR_AUTOINDEX'] = TRUE;
            return 'FileReader';
        } */
    }

}

return new MyAppResolver;
