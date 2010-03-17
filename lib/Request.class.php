<?php
/**************************************************************************/
/* phpDaemon
/* ver. 0.2
/* License: LGPL
/* Web: http://phpdaemon.googlecode.com
/* ===========================
/* @class Request
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Request class.
/**************************************************************************/
class Request
{
 public $idAppQueue;
 public $mpartstate = 0;
 public $mpartoffset = 0;
 public $mpartcondisp = FALSE;
 public $headers = array('STATUS' => '200 OK');
 public $headers_sent = FALSE;
 public $appInstance;
 public $boundary = FALSE;
 public $aborted = FALSE;
 public $state = 1;
 public $codepoint;
 public $sendfp;
 public static $hvaltr = array(';' => '&', ' ' => '');
 public static $htr = array('-' => '_');
 public $attrs;
 public $shutdownFuncs = array();
 public $sleepuntil;
 public $running = FALSE;
 public $upstream;
 public $answerlen = 0;
 public $contentLength;
 public function __construct($appInstance,$upstream,$req)
 {
  $this->appInstance = $appInstance;
  $this->upstream = $upstream;
  $this->attrs = $req->attrs;
  if (Daemon::$settings['expose']) {$this->header('X-Powered-By: phpDaemon '.Daemon::$version);}
  $this->onWakeup();
  $this->init();
  $this->onSleep();
 }
 public function __toString()
 {
  return 'Request of type '.get_class($this);
 }
 public function chunked()
 {
  $this->header('Transfer-Encoding: chunked');
  $this->attrs->chunked = TRUE;
 }
 public function init()
 {
 }
 public function getString(&$var)
 {
  if (!is_string($var)) {return '';}
  return $var;
 }
 public function registerShutdownFunction($callback)
 {
  $this->shutdownFuncs[] = $callback;
 }
 public function unregisterShutdownFunction($callback)
 {
  if (($k = array_search($callback,$this->shutdownFuncs)) !== FALSE) {$this->shutdownFuncs[] = $callback;}
 }
 public function codepoint($p)
 {
  if ($this->codepoint !== $p)
  {
   $this->codepoint = $p;
   return TRUE;
  }
  return FALSE;
 }
 public function sleep($time = 0,$set = FALSE)
 {
  $this->sleepuntil = microtime(TRUE)+$time;
  if (!$set) {throw new RequestSleepException;}
 }
 public function terminate($s = NULL)
 {
  if (is_string($s)) {$this->out($s);}
  throw new RequestTerminatedException;
 }
 public function wakeup()
 {
  $this->state = 1;
 }
 public function call()
 {
  if ($this->state === 0) {return 1;}
  if ($this->attrs->params_done)
  {
   if (isset($this->appInstance->passphrase))
   {
    if (!isset($this->attrs->server['PASSPHRASE']) || ($this->appInstance->passphrase !== $this->attrs->server['PASSPHRASE']))
    {
     $this->state = 1;
     return 1;
    }
   }
  }
  if ($this->attrs->params_done && $this->attrs->stdin_done)
  {
   $this->state = 2;
   $this->onWakeup();
   try
   {
    $this->state = $this->run();
    if ($this->state === NULL) {Daemon::log('Method '.get_class($this).'::run() returned null.');}
   }
   catch (RequestSleepException $e)
   {
    $this->state = 3;
   }
   catch (RequestTerminatedException $e)
   {
    $this->state = 1;
   }
   if ($this->state === 1) {$this->finish();}
   $this->onSleep();
   return $this->state;
  }
  return 0;
 }
 public function onAbort()
 {
 }
 public function onFinish()
 {
 }
 public function onWakeup()
 {
  if (!Daemon::$compatMode) {Daemon::$worker->setStatus(2);}
  ob_flush();
  $this->running = TRUE;
  Daemon::$req = $this;
  $_GET = &$this->attrs->get;
  $_POST = &$this->attrs->post;
  $_COOKIE = &$this->attrs->cookie;
  $_REQUEST = &$this->attrs->request;
  $_SESSION = &$this->attrs->session;
  $_FILES = &$this->attrs->files;
  $_SERVER = &$this->attrs->server;
 }
 public function onSleep()
 {
  ob_flush();
  if (!Daemon::$compatMode) {Daemon::$worker->setStatus(1);}
  Daemon::$req = NULL;
  $this->running = FALSE;
 }
 public function header($s)
 {
  if ($this->headers_sent)
  {
   throw new Exception('headers already sent',1);
   return FALSE;
  }
  $e = explode(':',$s,2);
  if (!isset($e[1]))
  {
   $e[0] = 'STATUS';
   if (strncmp($s,'HTTP/',5) === 0) {$s = substr($s,9);}
  }
  $k = strtr(strtoupper($e[0]),Request::$htr);
  $this->headers[$k] = $s;
  if ($k === 'CONTENT_LENGTH') {$this->contentLength = (int) $e[1];}
  if (Daemon::$compatMode) {header($s);}
  return TRUE;
 }
 public function parseParams()
 {
  if (isset($this->attrs->server['CONTENT_TYPE']) && !isset($this->attrs->server['HTTP_CONTENT_TYPE']))
  {
   $this->attrs->server['HTTP_CONTENT_TYPE'] = $this->attrs->server['CONTENT_TYPE'];
  }
  if (isset($this->attrs->server['QUERY_STRING'])) {$this->parse_str($this->attrs->server['QUERY_STRING'],$this->attrs->get);}
  if (isset($this->attrs->server['REQUEST_METHOD']) && ($this->attrs->server['REQUEST_METHOD'] == 'POST') && isset($this->attrs->server['HTTP_CONTENT_TYPE']))
  {
   parse_str(strtr($this->attrs->server['HTTP_CONTENT_TYPE'],Request::$hvaltr),$contype);
   if (isset($contype['multipart/form-data']) && (isset($contype['boundary']))) {$this->boundary = $contype['boundary'];}
  }
  if (isset($this->attrs->server['HTTP_COOKIE'])) {$this->parse_str(strtr($this->attrs->server['HTTP_COOKIE'],Request::$hvaltr),$this->attrs->cookie);}
  if (isset($this->attrs->server['HTTP_AUTHORIZATION']))
  {
   $e = explode(' ',$this->attrs->server['HTTP_AUTHORIZATION'],2);
   if (($e[0] == 'Basic') && isset($e[1]))
   {
    $e[1] = base64_decode($e[1]);
    $e = explode(':',$e[1],2);
    if (isset($e[1])) {list($this->attrs->server['PHP_AUTH_USER'],$this->attrs->server['PHP_AUTH_PW']) = $e;}
   }
  }
  $this->onParsedParams();
 }
 public function onParsedParams() {}
 public function combinedOut($s)
 {
  if (!$this->headers_sent)
  {
   $e = explode("\r\n\r\n",$s,2);
   $h = explode("\r\n",$e[0]);
   foreach ($h as &$l) {$this->header($l);}
   if (isset($e[1])) {return $this->out($e[1]);}
   return TRUE;
  }
  else {return $this->out($s);}
 }
 public function out($s,$flush = TRUE)
 {
  //Daemon::log('Output (len. '.strlen($s).', '.($this->headers_sent?'headers sent':'headers not sent').'): \''.$s.'\'');
  if ($flush) {ob_flush();}
  if ($this->aborted) {return;}
  $l = strlen($s);
  $this->answerlen += $l;
  if (!$this->headers_sent)
  {
   $h = isset($this->headers['STATUS'])?$this->attrs->server['SERVER_PROTOCOL'].' '.$this->headers['STATUS']."\r\n":'';
   if ($this->attrs->chunked) {$this->header('Transfer-Encoding: chunked');}
   foreach ($this->headers as $k => $line)
   {
    if ($k !== 'STATUS') {$h .= $line."\r\n";}
   }
   $h .= "\r\n";
   $this->headers_sent = TRUE;
   if (!Daemon::$compatMode)
   {
    if (!$this->attrs->chunked && !$this->sendfp) {return $this->upstream->requestOut($this,$h.$s);}
    $this->upstream->requestOut($this,$h);
   }
  }
  if ($this->attrs->chunked)
  {
   for ($o = 0; $o < $l;)
   {
    $c = min(Daemon::$parsedSettings['chunksize'],$l-$o);
    $chunk = dechex($c)."\r\n"
     .($c === $l?$s:binarySubstr($s,$o,$c)) // content
     ."\r\n";
    if ($this->sendfp) {fwrite($this->sendfp,$chunk);}
    else {$this->upstream->requestOut($this,$chunk);}
    $o += $c;
   }
  }
  else
  {
   if ($this->sendfp)
   {
    fwrite($this->sendfp,$s);
    return TRUE;
   }
   if (Daemon::$compatMode)
   {
    echo $s;
    return TRUE;
   }
   return $this->upstream->requestOut($this,$s);
  }
 }
 public function headers_sent() {return $this->headers_sent;}
 public function headers_list()
 {
  return array_values($this->headers);
 }
 public function parseStdin()
 {
  do
  {
   if ($this->boundary === FALSE) {break;}
   $continue = FALSE;
   if ($this->mpartstate === 0) // seek to the nearest boundary
   {
    if (($p = strpos($this->attrs->stdinbuf,$ndl = '--'.$this->boundary."\r\n",$this->mpartoffset)) !== FALSE)
    {
     // we have found the nearest boundary at position $p
     $this->mpartoffset = $p+strlen($ndl);
     $this->mpartstate = 1;
     $continue = TRUE;
    }
   }
   elseif ($this->mpartstate === 1) // parse the part's headers
   {
    $this->mpartcondisp = FALSE;
    if (($p = strpos($this->attrs->stdinbuf,"\r\n\r\n",$this->mpartoffset)) !== FALSE) // we got all of the headers
    {
     $h = explode("\r\n",binarySubstr($this->attrs->stdinbuf,$this->mpartoffset,$p-$this->mpartoffset));
     $this->mpartoffset = $p+4;
     $this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf,$this->mpartoffset);
     $this->mpartoffset = 0;
     for ($i = 0, $s = sizeof($h); $i < $s; ++$i)
     {
      $e = explode(':',$h[$i],2);
      $e[0] = strtr(strtoupper($e[0]),Request::$htr);
      if (isset($e[1])) {$e[1] = ltrim($e[1]);}
      if (($e[0] == 'CONTENT_DISPOSITION') && isset($e[1]))
      {
       parse_str(strtr($e[1],Request::$hvaltr),$this->mpartcondisp);
       if (!isset($this->mpartcondisp['form-data'])) {break;}
       if (!isset($this->mpartcondisp['name'])) {break;}
       $this->mpartcondisp['name'] = trim($this->mpartcondisp['name'],'"');
       if (isset($this->mpartcondisp['filename']))
       {
        $this->mpartcondisp['filename'] = trim($this->mpartcondisp['filename'],'"');
        if (!ini_get('file_uploads')) {break;}
        $this->attrs->files[$this->mpartcondisp['name']] = array(
         'name' => $this->mpartcondisp['filename'],
         'type' => '',
         'tmp_name' => '',
         'error' => UPLOAD_ERR_OK,
         'size' => 0,
        );
        $tmpdir = ini_get('upload_tmp_dir');
        if ($tmpdir === FALSE)
        {
         $this->attrs->files[$this->mpartcondisp['name']]['fp'] = FALSE;
         $this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_NO_TMP_DIR;
        }
        else
        {
         $this->attrs->files[$this->mpartcondisp['name']]['fp'] = @fopen($this->attrs->files[$this->mpartcondisp['name']]['tmp_name'] = tempnam($tmpdir,'php'),'w');
         if (!$this->attrs->files[$this->mpartcondisp['name']]['fp']) {$this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_CANT_WRITE;}
        }
        $this->mpartstate = 3;
       }
       else
       {
        $this->attrs->post[$this->mpartcondisp['name']] = '';
       }
      }
      elseif (($e[0] == 'CONTENT_TYPE') && isset($e[1]))
      {
       if (isset($this->mpartcondisp['name']) && isset($this->mpartcondisp['filename']))
       {
        $this->attrs->files[$this->mpartcondisp['name']]['type'] = $e[1];
       }
      }
     }
     if ($this->mpartstate === 1) {$this->mpartstate = 2;}
     $continue = TRUE;
    }
   }
   elseif (($this->mpartstate === 2) || ($this->mpartstate === 3)) // process the body
   {
    if ((($p = strpos($this->attrs->stdinbuf,$ndl = "\r\n--".$this->boundary."\r\n",$this->mpartoffset)) !== FALSE)
     || (($p = strpos($this->attrs->stdinbuf,$ndl = "\r\n--".$this->boundary."--\r\n",$this->mpartoffset)) !== FALSE)
    )
    {
     if (($this->mpartstate === 2) && isset($this->mpartcondisp['name'])) {$this->attrs->post[$this->mpartcondisp['name']] .= binarySubstr($this->attrs->stdinbuf,$this->mpartoffset,$p-$this->mpartoffset);}
     elseif (($this->mpartstate === 3) && isset($this->mpartcondisp['filename']))
     {
      if ($this->attrs->files[$this->mpartcondisp['name']]['fp']) {fwrite($this->attrs->files[$this->mpartcondisp['name']]['fp'],binarySubstr($this->attrs->stdinbuf,$this->mpartoffset,$p-$this->mpartoffset));}
      $this->attrs->files[$this->mpartcondisp['name']]['size'] += $p-$this->mpartoffset;
     }
     if ($ndl === "\r\n--".$this->boundary."--\r\n")
     {
      $this->mpartoffset = $p+strlen($ndl);
      $this->mpartstate = 0; // we done at all
     }
     else
     {
      $this->mpartoffset = $p;
      $this->mpartstate = 1; // let us parse the next part
      $continue = TRUE;
     }
     $this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf,$this->mpartoffset);
     $this->mpartoffset = 0;
    }
    else
    {
     $p = strrpos($this->attrs->stdinbuf,"\r\n",$this->mpartoffset);
     if ($p !== FALSE)
     {
      if (($this->mpartstate === 2) && isset($this->mpartcondisp['name'])) {$this->attrs->post[$this->mpartcondisp['name']] .= binarySubstr($this->attrs->stdinbuf,$this->mpartoffset,$p-$this->mpartoffset);}
      elseif (($this->mpartstate === 3) && isset($this->mpartcondisp['filename']))
      {
       if ($this->attrs->files[$this->mpartcondisp['name']]['fp']) {fwrite($this->attrs->files[$this->mpartcondisp['name']]['fp'],binarySubstr($this->attrs->stdinbuf,$this->mpartoffset,$p-$this->mpartoffset));}
       $this->attrs->files[$this->mpartcondisp['name']]['size'] += $p-$this->mpartoffset;
       if (Daemon::parseSize(ini_get('upload_max_filesize')) < $this->attrs->files[$this->mpartcondisp['name']]['size'])
       {
        $this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_INI_SIZE;
       }
       if (isset($this->attrs->post['MAX_FILE_SIZE']) && ($this->attrs->post['MAX_FILE_SIZE'] < $this->attrs->files[$this->mpartcondisp['name']]['size']))
       {
        $this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_FORM_SIZE;
       }
      }
      $this->mpartoffset = $p;
      $this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf,$this->mpartoffset);
      $this->mpartoffset = 0;
     }
    }
   }
  }
  while ($continue);
 }
 public function abort()
 {
  if ($this->aborted) {return;}
  $this->aborted = TRUE;
  $this->onWakeup();
  $this->onAbort();
  if ((ignore_user_abort() === 1) && ($this->state > 1) && !Daemon::$compatMode)
  {
   if (!Daemon::$parsedSettings['keepalive']) {$this->upstream->closeConnection($this->attrs->connId);}
  }
  else
  {
   $this->finish(-1);
  }
  $this->onSleep();
 }
 public function postPrepare()
 {
  if (isset($this->attrs->server['REQUEST_METHOD']) && ($this->attrs->server['REQUEST_METHOD'] == 'POST'))
  {
   if ($this->boundary === FALSE) {$this->parse_str($this->attrs->stdinbuf,$this->attrs->post);}
   if (isset($this->attrs->server['REQUEST_BODY_FILE']) && Daemon::$settings['autoreadbodyfile'])
   {
    $this->readBodyFile();
   }
  }
 }
 public function stdin($c)
 {
  if ($c !== '')
  {
   $this->attrs->stdinbuf .= $c;
   $this->attrs->stdinlen += strlen($c);
  }
  if (!isset($this->attrs->server['HTTP_CONTENT_LENGTH']) || ($this->attrs->server['HTTP_CONTENT_LENGTH'] <= $this->attrs->stdinlen))
  {
   $this->attrs->stdin_done = TRUE;
   $this->postPrepare();
  }
  $this->parseStdin();
 }
 public function finish($status = 0)
 { // 0 - normal, -1 - abort, -2 - termination
  if ($this->state === 0) {return;}
  $this->state = 0;
  foreach ($this->shutdownFuncs as &$c) {call_user_func($c,$this);}
  $this->onFinish();
  if (Daemon::$compatMode) {return;}
  if ((Daemon::$parsedSettings['autogc'] > 0) && (Daemon::$worker->queryCounter > 0) && (Daemon::$worker->queryCounter % Daemon::$parsedSettings['autogc'] === 0))
  {
   gc_collect_cycles();
  }
  if (Daemon::$compatMode) {return;}
  ob_flush();
  if ($status !== -1)
  {
   if (!$this->headers_sent) {$this->out('');}
   // $status: 0 - FCGI_REQUEST_COMPLETE, 1 - FCGI_CANT_MPX_CONN, 2 - FCGI_OVERLOADED, 3 - FCGI_UNKNOWN_ROLE
   $appStatus = 0;
   $this->upstream->endRequest($this,$appStatus,$status);
   if ($this->sendfp) {fclose($this->sendfp);}
   if (isset($this->attrs->files))
   {
    foreach ($this->attrs->files as &$f)
    {
     if (($f['error'] === UPLOAD_ERR_OK) && file_exists($f['tmp_name']))
     {
      unlink($f['tmp_name']);
     }
    }
   }
   if (isset($this->attrs->session)) {session_commit();}
  }
 }
 public function readBodyFile()
 {
  if (!isset($this->attrs->server['REQUEST_BODY_FILE'])) {return FALSE;}
  $fp = fopen($this->attrs->server['REQUEST_BODY_FILE'],'rb');
  if (!$fp)
  {
   Daemon::log('Couldn\'t open request-body file \''.$this->attrs->server['REQUEST_BODY_FILE'].'\' (REQUEST_BODY_FILE).');
   return FALSE;
  }
  while (!feof($fp))
  {
   $this->stdin($this->fread($fp,4096));
  }
  fclose($fp);
  $this->attrs->stdin_done = TRUE;
 }
 public function parse_str_callback($m)
 {
  return urlencode(html_entity_decode('&#'.hexdec($m[1]).';',ENT_NOQUOTES,'utf-8'));
 }
 public function parse_str($s,&$array)
 {
  if ((stripos($s,'%u') !== FALSE) && preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is',$s,$m))
  {
   $s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i',array($this,'parse_str_callback'),$s);
  }
  parse_str($s,$array);
 }
}
class RequestSleepException extends Exception
{
}
class RequestTerminatedException extends Exception
{
}
