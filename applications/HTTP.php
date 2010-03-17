<?php
return new HTTP;
class HTTP extends AsyncServer
{
 public $initialLowMark = 1;
 public $initialHighMark = 0xFFFFFF;
 public $queuedReads = TRUE;
 public function init()
 {
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'listen'])) {Daemon::$settings[$k] = 'tcp://0.0.0.0';}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'listenport'])) {Daemon::$settings[$k] = 80;}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'logevents'])) {Daemon::$settings[$k] = 0;}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'logqueue'])) {Daemon::$settings[$k] = 0;}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'sendfile'])) {Daemon::$settings[$k] = 0;}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'sendfiledir'])) {Daemon::$settings[$k] = '/dev/shm/';}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'sendfileprefix'])) {Daemon::$settings[$k] = 'fcgi-';}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'sendfileonlybycommand'])) {Daemon::$settings[$k] = 0;}
  if (isset(Daemon::$settings[$k = 'mod'.$this->modname.'keepalive'])) {Daemon::$parsedSettings[$k] = Daemon::parseTime(Daemon::$settings[$k]);}
  else {Daemon::$parsedSettings[$k] = 0;}
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'enable'])) {Daemon::$settings[$k] = 0;}
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->bindSockets(Daemon::$settings['mod'.$this->modname.'listen'],Daemon::$settings['mod'.$this->modname.'listenport']);
  }
 }
 public function onAccepted($connId,$addr)
 {
  $this->poolState[$connId] = array(
   'n' => 0,
   'state' => 0,
   'addr' => $addr,
  );
 }
 public function requestOut($r,$s)
 {
  //Daemon::log('Request output (len. '.strlen($s).': \''.$s.'\'');
  $l = strlen($s);
  if (!isset(Daemon::$worker->pool[$r->attrs->connId]))
  {
   return FALSE;
  }
  Daemon::$worker->writePoolState[$r->attrs->connId] = TRUE;
  $w = event_buffer_write($this->buf[$r->attrs->connId],$s);
  if ($w === FALSE)
  {
   $r->abort();
   return FALSE;
  }
 }
 public function endRequest($req,$appStatus,$protoStatus)
 {
  if (Daemon::$settings['logevents']) {Daemon::log('[WORKER '.Daemon::$worker->pid.'] endRequest('.implode(',',func_get_args()).').');};
  if ($protoStatus === -1)
  {
   $this->closeConnection($req->attrs->connId);
  }
  else
  {
   if ($req->attrs->chunked)
   {
    Daemon::$worker->writePoolState[$req->attrs->connId] = TRUE;
    event_buffer_write($this->buf[$req->attrs->connId],"0\r\n\r\n");
   }
   if ((!Daemon::$parsedSettings['mod'.$this->modname.'keepalive']) || (!isset($req->attrs->server['HTTP_CONNECTION'])) || ($req->attrs->server['HTTP_CONNECTION'] !== 'keep-alive'))
   {
    $this->finishConnection($req->attrs->connId);
   }
  }
 }
 public function readConn($connId)
 {
  static $roles = array(
   1 => 'FCGI_RESPONDER',
   2 => 'FCGI_AUTHORIZER',
   3 => 'FCGI_FILTER',
  );
  $state = sizeof($this->poolState[$connId]);
  $buf = $this->read($connId,$this->readPacketSize);
  if ($buf === FALSE) {return;}
  if ($this->poolState[$connId]['state'] === 0) // begin
  {
   //Daemon::log('------------------------------begin request------------------------------------------------------------');
   ++Daemon::$worker->queryCounter;
   ++$this->poolState[$connId]['n'];
   $rid = $connId.'-'.$this->poolState[$connId]['n'];
   $this->poolState[$connId]['state'] = 1;
   $req = new stdClass();
   $req->attrs = new stdClass();
   $req->attrs->request = array();
   $req->attrs->get = array();
   $req->attrs->post = array();
   $req->attrs->cookie = array();
   $req->attrs->server = array();
   $req->attrs->files = array();
   $req->attrs->session = NULL;
   $req->attrs->connId = $connId;
   $req->attrs->id = $this->poolState[$connId]['n'];
   $req->attrs->params_done = FALSE;
   $req->attrs->stdin_done = FALSE;
   $req->attrs->stdinbuf = '';
   $req->attrs->stdinlen = 0;
   $req->attrs->inbuf = '';
   $req->attrs->chunked = TRUE;
   if (Daemon::$settings['mod'.$this->modname.'logqueue']) {Daemon::log('[WORKER '.Daemon::$worker->pid.'] new request queued.');}
   Daemon::$worker->queue[$rid] = $req;
   $this->poolQueue[$connId][$req->attrs->id] = $req;
  }
  else
  {
   $rid = $connId.'-'.$this->poolState[$connId]['n'];
   if (isset(Daemon::$worker->queue[$rid]))
   {
    $req = Daemon::$worker->queue[$rid];
   }
   else
   {
    Daemon::log('Unexpected input. Request ID: '.$rid.'.');
    return;
   }
  }
  if ($this->poolState[$connId]['state'] === 1)
  {
   $req->attrs->inbuf .= $buf;
   $buf = '';
   if (($p = strpos($req->attrs->inbuf,"\r\n\r\n")) !== FALSE)
   {
    $headers = binarySubstr($req->attrs->inbuf,0,$p);
    //Daemon::log('input headers = '.Daemon::var_dump($headers));
    $h = explode("\r\n",$headers);
    $req->attrs->inbuf = binarySubstr($req->attrs->inbuf,$p+4);
    $e = explode(' ',$h[0]);
    $u = parse_url($e[1]);
    $req->attrs->server['REQUEST_METHOD'] = $e[0];
    $req->attrs->server['REQUEST_URI'] = $u['path'].(isset($u['query'])?'?'.$u['query']:'');
    $req->attrs->server['DOCUMENT_URI'] = $u['path'];
    $req->attrs->server['PHP_SELF'] = $u['path'];
    $req->attrs->server['QUERY_STRING'] = isset($u['query'])?$u['query']:NULL;
    $req->attrs->server['SCRIPT_NAME'] = $req->attrs->server['DOCUMENT_URI'] = isset($u['path'])?$u['path']:'/';
    $req->attrs->server['SERVER_PROTOCOL'] = $e[2];
    list($req->attrs->server['REMOTE_ADDR'],$req->attrs->server['REMOTE_PORT']) = explode(':',$this->poolState[$connId]['addr']);
    for ($i = 1,$n = sizeof($h); $i < $n; ++$i)
    {
     $e = explode(': ',$h[$i]);
     if (isset($e[1])) {$req->attrs->server['HTTP_'.strtoupper(strtr($e[0],Request::$htr))] = $e[1];}
    }
    $req->attrs->params_done = TRUE;
    $req = Daemon::$appResolver->getRequest($req,$this,isset(Daemon::$settings[$k = 'mod'.$this->modname.'responder'])?Daemon::$settings[$k]:NULL);
    if ($req instanceof stdClass)
    {
     $this->endRequest($req,0,0);
     unset(Daemon::$worker->queue[$rid]);
    }
    else
    {
     if (Daemon::$settings['mod'.$this->modname.'sendfile'] && (!Daemon::$settings['mod'.$this->modname.'sendfileonlybycommand'] || isset($req->attrs->server['USE_SENDFILE'])) && !isset($req->attrs->server['DONT_USE_SENDFILE']))
     {
      $fn = tempnam(Daemon::$settings['mod'.$this->modname.'sendfiledir'],Daemon::$settings['mod'.$this->modname.'sendfileprefix']);
      $req->sendfp = fopen($fn,'wb');
      $req->header('X-Sendfile: '.$fn);
     }
     $req->parseParams();
     $req->stdin($req->attrs->inbuf);
     $req->attrs->inbuf = '';
     Daemon::$worker->queue[$rid] = $req;
     $this->poolState[$connId]['state'] = 2;
    }
   }
  }
  if ($this->poolState[$connId]['state'] === 2)
  {
   $req->stdin($buf);
   if (Daemon::$settings['logevents']) {Daemon::log('stdin_done = '.($req->attrs->stdin_done?'1':'0'));}
   if ($req->attrs->stdin_done) {$this->poolState[$req->attrs->connId]['state'] = 0;}
  }
  if ($req->attrs->stdin_done && $req->attrs->params_done)
  {
   if (($order = ini_get('request_order')) || ($order = ini_get('variables_order')))
   {
    for ($i = 0, $s = strlen($order); $i < $s; ++$i)
    {
     $char = $order[$i];
         if ($char == 'G') {$req->attrs->request += $req->attrs->get;}
     elseif ($char == 'P') {$req->attrs->request += $req->attrs->post;}
     elseif ($char == 'C') {$req->attrs->request += $req->attrs->cookie;}
    }
   }
   else {$req->attrs->request = $req->attrs->get + $req->attrs->post + $req->attrs->cookie;}
   $this->timeLastReq = time();
  }
 }
}
