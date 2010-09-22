<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
return array(
    'mod-fastcgi-enable' => 1,
    // mod-flashpolicy-enable' => 1,
    //'mod-websocketserver-enable' => 1,
    //'mod-websocketovercomet-enable' => 1,
    'max-requests' => 1000,
    'max-idle' => 0,
    'min-spare-workers' => 5,
    'max-spare-workers' => 20,
    'start-workers' => 20,
    'max-workers' => 50,
    'min-workers' => 20,
    'user' => '',
    'group' => '',
    'path' => __DIR__ . '/appResolver.php',
    //'mpm' => function() {},
    'max-concurrent-requests-per-worker' => 1000,
    'mod-fastcgi-sendfile' => 0,
    'mod-fastcgi-sendfile-dir' => '/shm/',
    'mod-fastcgi-sendfile-prefix' => 'fcgi-',
    'mod-fastcgi-sendfile-only-by-command' => 0,
);
