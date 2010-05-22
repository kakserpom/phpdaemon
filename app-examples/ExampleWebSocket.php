<?php
return new ExampleWebSocket;
class ExampleWebSocket extends AppInstance
{
 /* @method onHandshake
    @description Called when the connection is handshaked.
    @return object Session.
 */
 public function onHandshake($client) {return new ExampleWebSocketSession($client);}
 /* @method onReady
    @description Called when the worker is ready to go.
    @return void
 */
 public function onReady()
 {
  $this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
  if ($this->WS)
  {
   $this->WS->addRoute('exampleApp',array($this,'onHandshake'));
  }
 }
}
class ExampleWebSocketSession
{ 
 public $client; // Remote client
 /* @method __construct
    @description Called when client connected.
    @param object Remote client (WebSocketSession).
    @return void
 */
 public function __construct($client)
 {
  $this->client = $client;
 }
 /* @method onFrame
    @description Called when new frame recieved.
    @param string Frame's contents.
    @param integer Frame's type.
    @return void
 */
 public function onFrame($data,$type)
 {
  if ($data === 'ping')
  {
   $this->client->sendFrame('pong');
  }
 }
 /* @method onFinish
    @description Called when session finished.
    @return void
 */
 public function onFinish() {}
 /* @method gracefulShutdown
    @description Called when the worker is going to shutdown.
    @return boolean Ready to shutdown?
 */
 public function gracefulShutdown() {return TRUE;}
}
