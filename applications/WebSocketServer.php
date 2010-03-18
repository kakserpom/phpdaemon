<?php
return new WebSocketServer;
class WebSocketServer extends AsyncServer
{
 public $sessions = array();
 public $routes = array();
 const BINARY = 0x80;
 const STRING = 0x00;
 public function init()
 {
  Daemon::$settings += array(
   'mod'.$this->modname.'listen' => 'tcp://0.0.0.0',
   'mod'.$this->modname.'listenport' => 8047,
   'mod'.$this->modname.'enable' => 0
  );
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->bindSockets(Daemon::$settings['mod'.$this->modname.'listen'],Daemon::$settings['mod'.$this->modname.'listenport']);
  }
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->enableSocketEvents();
  }
 }
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new WebSocketSession($connId,$this);
  $this->sessions[$connId]->clientAddr = $addr;
 }
 public function onEvent($packet)
 {
  if (Daemon::$settings['logevents']) {Daemon::log(__METHOD__.': '.Daemon::var_dump($packet));}
  $evName = isset($packet['event']['name'])?(string) $packet['event']['name']:'';
  $packet['event']['_ts'] = microtime(TRUE);
  $packet['event']['currentTime'] = microtime(TRUE); // hack
  if (isset($this->events[$evName]))
  {
   foreach ($this->events[$evName] as $connId => &$v)
   {
    if (isset($this->sessions[$connId])) {$this->sessions[$connId]->send($packet);}
   }
  }
 }
 public function beginRequest($packet) {return FALSE;}
}
class WebSocketSession extends SocketSession
{
 public $handshaked = FALSE;
 public $upstream = FALSE;
 public $server = array();
 public $firstline = FALSE;
 public function sendFrame($data,$type = 0x00)
 {
  if (($type & 0x80) === 0x80)
  {
   $n = strlen($data);
   $len = '';
   $pos = 0;
   char:
   ++$pos;
   $c = $n >> 0 & 0x7F;
   $n = $n >> 7;
   if ($pos != 1) {$c += 0x80;}
   if ($c != 0x80)
   {
    $len = chr($c).$len;
    goto char;
   };
   $this->write("\x80".$len.$data);
  }
  else {$this->write("\x00".$data."\xFF");}
 }
 public function onFinish()
 {
  if (Daemon::$settings['logevents']) {Daemon::log(get_class($this).'::'.__METHOD__.' invoked');}
  if ($this->upstream) {$this->upstream->onFinish();}
  unset($this->upstream);
  unset($this->appInstance->sessions[$this->connId]);
 }
 public function onFrame($data,$type)
 {
  if (!$this->upstream) {return FALSE;}
  $this->upstream->onFrame($data,$type);
  return TRUE;
 }
 public function onHandshake()
 {
  $e = explode('/',$this->server['DOCUMENT_URI']);
  $appName = isset($e[1])?$e[1]:'';
  if (!isset($this->appInstance->routes[$appName]))
  {
   if (isset(Daemon::$settings['logerrors']) && Daemon::$settings['logerrors']) {Daemon::log(__METHOD__.': undefined route \''.$appName.'\'.');}
   return FALSE;
  }
  if (!$this->upstream = call_user_func($this->appInstance->routes[$appName],$this)) {return FALSE;}
  return TRUE;
 }
 public function gracefulShutdown()
 {
  if ((!$this->upstream) || $this->upstream->gracefulShutdown())
  {
   $this->finish();
   return TRUE;
  }
  return FALSE;
 }
 public function stdin($buf)
 {
  $this->buf .= $buf;
  if (!$this->handshaked)
  {
   $i = 0;
   while (($l = $this->gets()) !== FALSE)
   {
    if ($i++ > 100) {break;}
    if ($l === "\r\n")
    {
     $this->handshaked = TRUE;
     if ($this->onHandshake())
     {
      if (!isset($this->server['HTTP_ORIGIN'])) {$this->server['HTTP_ORIGIN'] = '';}
      $reply = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
        ."Upgrade: WebSocket\r\n"
        ."Connection: Upgrade\r\n"
        .'WebSocket-Origin: '.$this->server['HTTP_ORIGIN']."\r\n"
        .'WebSocket-Location: ws://'.$this->server['HTTP_HOST'].$this->server['REQUEST_URI']."\r\n";
      if (isset($this->server['HTTP_WEBSOCKET_PROTOCOL']))
      {
       $reply .= 'WebSocket-Protocol: '.$this->server['HTTP_WEBSOCKET_PROTOCOL']."\r\n";
      }
      $reply .= "\r\n";
      $this->write($reply);
     }
     else
     {
      $this->finish();
     }
     break;
    }
    if (!$this->firstline)
    {
     $this->firstline = TRUE;     
     $e = explode(' ',$l);
     $u = parse_url(isset($e[1])?$e[1]:'');
     $this->server['REQUEST_METHOD'] = $e[0];
     $this->server['REQUEST_URI'] = $u['path'].(isset($u['query'])?'?'.$u['query']:'');
     $this->server['DOCUMENT_URI'] = $u['path'];
     $this->server['PHP_SELF'] = $u['path'];
     $this->server['QUERY_STRING'] = isset($u['query'])?$u['query']:NULL;
     $this->server['SCRIPT_NAME'] = $this->server['DOCUMENT_URI'] = isset($u['path'])?$u['path']:'/';
     $this->server['SERVER_PROTOCOL'] = isset($e[2])?$e[2]:'';
     list($this->server['REMOTE_ADDR'],$this->server['REMOTE_PORT']) = explode(':',$this->clientAddr);
    }
    else
    {
     $e = explode(': ',$l);
     if (isset($e[1])) {$this->server['HTTP_'.strtoupper(strtr($e[0],Request::$htr))] = rtrim($e[1],"\r\n");}
    }
   }
  }
  if ($this->handshaked)
  {
   while (strlen($this->buf) >= 2)
   {
    $frametype = ord(binarySubstr($this->buf,0,1));
    if (($frametype & 0x80) === 0x80)
    {
     $len = 0;
     $i = 0;
     do
     {
      $b = ord(binarySubstr($this->buf,++$i,1));
      $n = $b & 0x7F;
      $len *= 0x80;
      $len += $n;
     }
     while ($b > 0x80);
     $data = binarySubstr($this->buf,2,$len);
     $this->buf = binarySubstr($this->buf,2+$len);
     $this->onFrame($data,$frametype);
    }
    else
    {
     if (($p = strpos($this->buf,"\xFF")) !== FALSE)
     {
      $data = binarySubstr($this->buf,1,$p-1);
      $this->buf = binarySubstr($this->buf,$p+1);
      $this->onFrame($data,$frametype);
     }
     else {break;}
    }
   }
  }
 }
}
