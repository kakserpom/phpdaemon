<?php
return new ExampleWebSocket;
class ExampleWebSocket extends AppInstance
{
 public function onHandshake($client) {return new ExampleWebSocketSession($client);}
 public function onReady()
 {
  $this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
  if ($this->WS)
  {
   $this->WS->routes['exampleApp'] = array($this,'onHandshake');
  }
 }
}
class ExampleWebSocketSession
{ 
 public $client;
 public function __construct($client)
 {
  $this->client = $client;
 }
 public function onFrame($data,$type)
 {
  if ($data === 'ping')
  {
   $this->client->sendFrame('pong');
  }
 }
 public function onFinish() {}
}
