<?php
return new FastCGI;
class FastCGI extends AsyncServer
{
 public $initialLowMark = 8; // initial value of the minimal amout of bytes in buffer
 public $initialHighMark = 0xFFFFFF; // initial value of the maximum amout of bytes in buffer
 public $queuedReads = TRUE;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'listen' =>  'tcp://127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
   'mod'.$this->modname.'listen-port' => 9000,
   'mod'.$this->modname.'allowed-clients' => '127.0.0.1',
   'mod'.$this->modname.'log-records' => 0,
   'mod'.$this->modname.'log-records-miss' => 0,
   'mod'.$this->modname.'log-events' => 0,
   'mod'.$this->modname.'log-queue' => 0,
   'mod'.$this->modname.'send-file' => 0,
   'mod'.$this->modname.'send-file-dir' => '/dev/shm',
   'mod'.$this->modname.'send-file-prefix' => 'fcgi-',
   'mod'.$this->modname.'send-file-onlybycommand' => 0,
   'mod'.$this->modname.'keepalive' => '0s',
   'mod'.$this->modname.'enable' => 0,
  ));
  Daemon::$parsedSettings['mod'.$this->modname.'keepalive'] = Daemon::parseTime(Daemon::$settings['mod'.$this->modname.'keepalive']);
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->allowedClients = explode(',',Daemon::$settings['mod'.$this->modname.'allowedclients']);
   $this->bindSockets(Daemon::$settings['mod'.$this->modname.'listen'],Daemon::$settings['mod'.$this->modname.'listenport']);
  }
 }
 /* @method requestOut
    @description Handles the output from downstream requests.
    @param object Request.
    @param string The output.
    @return void
 */
 public function requestOut($r,$s)
 {
  $l = strlen($s);
  if (Daemon::$settings['mod'.$this->modname.'logrecords']) {Daemon::log('[DEBUG] requestOut('.$r->attrs->id.',[...'.$l.'...])');}
  if (!isset(Daemon::$worker->pool[$r->attrs->connId]))
  {
   if (Daemon::$settings['mod'.$this->modname.'logrecordsmiss'] || Daemon::$settings['mod'.$this->modname.'logrecords']) {Daemon::log('[DEBUG] requestOut('.$r->attrs->id.',[...'.$l.'...]) connId '.$connId.' not found.');}
   return FALSE;
  }
  for ($o = 0; $o < $l;)
  {
   $c = min(Daemon::$parsedSettings['chunksize'],$l-$o);
   Daemon::$worker->writePoolState[$r->attrs->connId] = TRUE;
   $w = event_buffer_write($this->buf[$r->attrs->connId],
    "\x01" // protocol version
    ."\x06" // record type (STDOUT)
    .pack('nn',$r->attrs->id,$c) // id, content length
    ."\x00" // padding length
    ."\x00" // reserved
    .($c === $l?$s:binarySubstr($s,$o,$c)) // content
   );
   if ($w === FALSE)
   {
    $r->abort();
    return FALSE;
   }
   $o += $c;
  }
 }
 /* @method endRequest
    @description Handles the output from downstream requests.
    @return void
 */
 public function endRequest($req,$appStatus,$protoStatus)
 {
  $connId = $req->attrs->connId;
  if (Daemon::$settings['logevents']) {Daemon::log('[WORKER '.Daemon::$worker->pid.'] endRequest('.implode(',',func_get_args()).'): connId = '.$connId.'.');};
  $c = pack('NC',$appStatus,$protoStatus) // app status, protocol status
    ."\x00\x00\x00";
  Daemon::$worker->writePoolState[$connId] = TRUE;
  $w = event_buffer_write($this->buf[$connId],
    "\x01" // protocol version
    ."\x03" // record type (END_REQUEST)
    .pack('nn',$req->attrs->id,strlen($c)) // id, content length
    ."\x00" // padding length
    ."\x00" // reserved
    .$c // content
  ); 
  if ($protoStatus === -1)
  {
   $this->closeConnection($connId);
  }
  elseif (!Daemon::$parsedSettings['mod'.$this->modname.'keepalive'])
  {
   $this->finishConnection($connId);
  }
 }
 /* @method readConn
    @description Reads data from the connection's buffer.
    @param integer Connection's ID.
    @return void
 */
 public function readConn($connId)
 {
  static $roles = array(
   1 => 'FCGI_RESPONDER',
   2 => 'FCGI_AUTHORIZER',
   3 => 'FCGI_FILTER',
  );
  static $reqtypes = array(
   1 => 'FCGI_BEGIN_REQUEST',
   2 => 'FCGI_ABORT_REQUEST',
   3 => 'FCGI_END_REQUEST',
   4 => 'FCGI_PARAMS',
   5 => 'FCGI_STDIN',
   6 => 'FCGI_STDOUT',
   7 => 'FCGI_STDERR',
   8 => 'FCGI_DATA',
   9 => 'FCGI_GET_VALUES',
   10 => 'FCGI_GET_VALUES_RESULT',
   11 => 'FCGI_UNKNOWN_TYPE',
   11 => 'FCGI_MAXTYPE',
  );
  $state = sizeof($this->poolState[$connId]);
  if ($state === 0)
  {
   $header = $this->read($connId,8);
   if ($header === FALSE) {return;}
   $r = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved',$header);
   if ($r['conlen'] > 0) {event_buffer_watermark_set($this->buf[$connId],EV_READ,$r['conlen'],0xFFFFFF);}
   $this->poolState[$connId][0] = $r;
   ++$state;
  }
  else {$r = $this->poolState[$connId][0];}
  if ($state === 1)
  {
   $c = ($r['conlen'] === 0)?'':$this->read($connId,$r['conlen']);
   if ($c === FALSE) {return;}
   if ($r['padlen'] > 0) {event_buffer_watermark_set($this->buf[$connId],EV_READ,$r['padlen'],0xFFFFFF);}
   $this->poolState[$connId][1] = $c;
   ++$state;
  }
  else {$c = $this->poolState[$connId][1];}
  if ($state === 2)
  {
   $pad = ($r['padlen'] === 0)?'':$this->read($connId,$r['padlen']);
   if ($pad === FALSE) {return;}
   $this->poolState[$connId][2] = $pad;
  }
  else {$pad = $this->poolState[$connId][2];}
  $this->poolState[$connId] = array();
  $type = &$r['type'];
  $r['ttype'] = isset($reqtypes[$type])?$reqtypes[$type]:$type;
  $rid = $connId.'-'.$r['reqid'];
  if (Daemon::$settings['mod'.$this->modname.'logrecords']) {Daemon::log('[DEBUG] FastCGI-record #'.$r['type'].' ('.$r['ttype'].'). Request ID: '.$rid.'. Content length: '.$r['conlen'].' ('.strlen($c).') Padding length: '.$r['padlen'].' ('.strlen($pad).')');}
  if ($type == 1) // FCGI_BEGIN_REQUEST
  {
    ++Daemon::$worker->queryCounter;
   $rr = unpack('nrole/Cflags',$c);
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
   $req->attrs->trole = $roles[$rr['role']];
   $req->attrs->flags = $rr['flags'];
   $req->attrs->id = $r['reqid'];
   $req->attrs->params_done = FALSE;
   $req->attrs->stdin_done = FALSE;
   $req->attrs->stdinbuf = '';
   $req->attrs->stdinlen = 0;
   $req->attrs->chunked = FALSE;
   if (Daemon::$settings['mod'.$this->modname.'logqueue']) {Daemon::log('[WORKER '.Daemon::$worker->pid.'] new request queued.');}
   Daemon::$worker->queue[$rid] = $req;
   $this->poolQueue[$connId][$req->attrs->id] = $req;
  }
  elseif (isset(Daemon::$worker->queue[$rid]))
  {
   $req = Daemon::$worker->queue[$rid];
  }
  else
  {
   Daemon::log('Unexpected FastCGI-record #'.$r['type'].' ('.$r['ttype'].'). Request ID: '.$rid.'.');
   return;
  }
  if ($type === 2) // FCGI_ABORT_REQUEST
  {
   $req->abort();
  }
  elseif ($type === 4) // FCGI_PARAMS
  {
   if ($c === '')
   {
    $req->attrs->params_done = TRUE;
    $req = Daemon::$appResolver->getRequest($req,$this);
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
     Daemon::$worker->queue[$rid] = $req;
    }
   }
   else
   {
    $p = 0;
    while ($p < $r['conlen'])
    {
     if (($namelen = ord($c{$p})) < 128) {++$p;}
     else
     {
      $u = unpack('Nlen',chr(ord($c{$p}) & 0x7f).binarySubstr($c,$p+1,3));
      $namelen = $u['len'];
      $p += 4;
     }
     if (($vlen = ord($c{$p})) < 128) {++$p;}
     else
     {
      $u = unpack('Nlen',chr(ord($c{$p}) & 0x7f).binarySubstr($c,$p+1,3));
      $vlen = $u['len'];
      $p += 4;
     }
     $req->attrs->server[binarySubstr($c,$p,$namelen)] = binarySubstr($c,$p+$namelen,$vlen);
     $p += $namelen+$vlen;
    }
   }
  }
  elseif ($type === 5) // FCGI_STDIN
  {
   if ($c === '') {$req->attrs->stdin_done = TRUE;}
   $req->stdin($c);
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
