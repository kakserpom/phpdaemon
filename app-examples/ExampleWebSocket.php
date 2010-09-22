<?php

return new ExampleWebSocket;

class ExampleWebSocket extends AppInstance
{
    /* @method onHandshake
      @description Called when the connection is handshaked.
      @return object Session.
     */

    public function onHandshake($client)
    {
        return new ExampleWebSocketSession($client);
    }

    /* @method onReady
      @description Called when the worker is ready to go.
      @return void
     */

    public function onReady()
    {
        if ($this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer')) {
            $this->WS->addRoute('exampleApp', array($this, 'onHandshake'));
        }
    }

}

class ExampleWebSocketSession extends WebSocketRoute
{
    /* @method onFrame
      @description Called when new frame received.
      @param string Frame's contents.
      @param integer Frame's type.
      @return void
     */

    public function onFrame($data, $type)
    {
        if ($data === 'ping') {
            $this->client->sendFrame('pong', WebSocketSERVER::STRING, function($client) {
                        Daemon::log('ExampleWebSocket: \'pong\' received by client.');
                    });
        }
    }

}
