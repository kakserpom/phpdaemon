<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class WebSocketRoute
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description WebSocketRoute class.
/**************************************************************************/
class WebSocketRoute
{
    public $client; // Remote client
    public $appInstance;
    /* @method __construct
    @description Called when client connected.
    @param object Remote client (WebSocketSession).
    @return void
    */
    public function __construct($client, $appInstance = NULL)
    {
        $this->client = $client;
        if ($appInstance) {
            $this->appInstance = $appInstance;
        }
    }
    /* @method onHandshake
    @description Called when the connection is handshaked.
    @return void
    */
    public function onHandshake()
    {
    }
    /* @method onFinish
    @description Called when session finished.
    @return void
    */
    public function onFinish()
    {
    }
    /* @method gracefulShutdown
    @description Called when the worker is going to shutdown.
    @return boolean Ready to shutdown?
    */
    public function gracefulShutdown()
    {
        return TRUE;
    }
}
