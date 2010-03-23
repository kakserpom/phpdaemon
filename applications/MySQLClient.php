<?php
return new MySQLClient;
class MySQLClient extends AsyncServer
{
 public $sessions = array();
 public $servConn = array();
 public $prefix = '';
 public $requests = array();
 public $cursors = array();
 public $lastReqId = 0;
 public $dbname = '';
 public $insertId;
 public $affectedRows;
 const CLIENT_LONG_PASSWORD	= 1;	/* new more secure passwords */
 const CLIENT_FOUND_ROWS	= 2;	/* Found instead of affected rows */
 const CLIENT_LONG_FLAG = 4;	/* Get all column flags */
 const CLIENT_CONNECT_WITH_DB = 8;	/* One can specify db on connect */
 const CLIENT_NO_SCHEMA = 16;	/* Don't allow database.table.column */
 const CLIENT_COMPRESS	= 32;	/* Can use compression protocol */
 const CLIENT_ODBC = 64;	/* Odbc client */
 const CLIENT_LOCAL_FILES = 128;	/* Can use LOAD DATA LOCAL */
 const CLIENT_IGNORE_SPACE = 256;	/* Ignore spaces before '(' */
 const CLIENT_PROTOCOL_41	= 512;	/* New 4.1 protocol */
 const CLIENT_INTERACTIVE	= 1024;	/* This is an interactive client */
 const CLIENT_SSL = 2048;	/* Switch to SSL after handshake */
 const CLIENT_IGNORE_SIGPIPE = 4096;    /* IGNORE sigpipes */
 const CLIENT_TRANSACTIONS = 8192;	/* Client knows about transactions */
 const CLIENT_RESERVED = 16384;   /* Old flag for 4.1 protocol  */
 const CLIENT_SECURE_CONNECTION = 32768; /* New 4.1 authentication */
 const CLIENT_MULTI_STATEMENTS = 65536;   /* Enable/disable multi-stmt support */
 const CLIENT_MULTI_RESULTS = 131072; /* Enable/disable multi-results */

 const COM_SLEEP = 0x00; /* (none, this is an internal thread state) */
 const COM_QUIT = 0x01; /* mysql_close */ 
 const COM_INIT_DB = 0x02; /* mysql_select_db */ 
 const COM_QUERY = 0x03; /* mysql_real_query */ 
 const COM_FIELD_LIST = 0x04; /* mysql_list_fields */ 
 const COM_CREATE_DB = 0x05; /* mysql_create_db (deprecated) */ 
 const COM_DROP_DB = 0x06; /* mysql_drop_db (deprecated) */ 
 const COM_REFRESH = 0x07; /* mysql_refresh */ 
 const COM_SHUTDOWN = 0x08; /* mysql_shutdown */ 
 const COM_STATISTICS = 0x09; /* mysql_stat */ 
 const COM_PROCESS_INFO = 0x0a; /* mysql_list_processes */ 
 const COM_CONNECT = 0x0b; /* (none, this is an internal thread state) */ 
 const COM_PROCESS_KILL = 0x0c; /* mysql_kill */ 
 const COM_DEBUG = 0x0d; /* mysql_dump_debug_info */ 
 const COM_PING = 0x0e; /* mysql_ping */ 
 const COM_TIME = 0x0f; /* (none, this is an internal thread state) */ 
 const COM_DELAYED_INSERT = 0x10; /* (none, this is an internal thread state) */ 
 const COM_CHANGE_USER = 0x11; /* mysql_change_user */ 
 const COM_BINLOG_DUMP = 0x12; /* sent by the slave IO thread to request a binlog */ 
 const COM_TABLE_DUMP = 0x13; /* LOAD TABLE ... FROM MASTER (deprecated) */ 
 const COM_CONNECT_OUT = 0x14; /* (none, this is an internal thread state) */ 
 const COM_REGISTER_SLAVE = 0x15; /* sent by the slave to register with the master (optional) */ 
 const COM_STMT_PREPARE = 0x16; /* mysql_stmt_prepare */ 
 const COM_STMT_EXECUTE = 0x17; /* mysql_stmt_execute */ 
 const COM_STMT_SEND_LONG_DATA = 0x18; /* mysql_stmt_send_long_data */ 
 const COM_STMT_CLOSE = 0x19; /* mysql_stmt_close */ 
 const COM_STMT_RESET = 0x1a; /* mysql_stmt_reset */ 
 const COM_SET_OPTION = 0x1b; /* mysql_set_server_option */ 
 const COM_STMT_FETCH = 0x1c; /* mysql_stmt_fetch */ 

 const FIELD_TYPE_DECIMAL = 0x00;
 const FIELD_TYPE_TINY = 0x01;
 const FIELD_TYPE_SHORT = 0x02;
 const FIELD_TYPE_LONG = 0x03;
 const FIELD_TYPE_FLOAT = 0x04;
 const FIELD_TYPE_DOUBLE = 0x05;
 const FIELD_TYPE_NULL = 0x06;
 const FIELD_TYPE_TIMESTAMP = 0x07;
 const FIELD_TYPE_LONGLONG = 0x08;
 const FIELD_TYPE_INT24 = 0x09;
 const FIELD_TYPE_DATE = 0x0a;
 const FIELD_TYPE_TIME = 0x0b;
 const FIELD_TYPE_DATETIME = 0x0c;
 const FIELD_TYPE_YEAR = 0x0d;
 const FIELD_TYPE_NEWDATE = 0x0e;
 const FIELD_TYPE_VARCHAR = 0x0f;
 const FIELD_TYPE_BIT = 0x10;
 const FIELD_TYPE_NEWDECIMAL = 0xf6;
 const FIELD_TYPE_ENUM = 0xf7;
 const FIELD_TYPE_SET = 0xf8;
 const FIELD_TYPE_TINY_BLOB = 0xf9;
 const FIELD_TYPE_MEDIUM_BLOB = 0xfa;
 const FIELD_TYPE_LONG_BLOB = 0xfb;
 const FIELD_TYPE_BLOB = 0xfc;
 const FIELD_TYPE_VAR_STRING = 0xfd;
 const FIELD_TYPE_STRING = 0xfe;
 const FIELD_TYPE_GEOMETRY = 0xff;
 
 const NOT_NULL_FLAG = 0x1;
 const PRI_KEY_FLAG = 0x2;
 const UNIQUE_KEY_FLAG = 0x4;
 const MULTIPLE_KEY_FLAG = 0x8;
 const BLOB_FLAG = 0x10;
 const UNSIGNED_FLAG = 0x20;
 const ZEROFILL_FLAG = 0x40;
 const BINARY_FLAG = 0x80;
 const ENUM_FLAG = 0x100;
 const AUTO_INCREMENT_FLAG = 0x200;
 const TIMESTAMP_FLAG = 0x400;
 const SET_FLAG = 0x800;

 public $ready = FALSE;
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'server' => 'mysql://root@127.0.0.1',
   'mod'.$this->modname.'port' => 3306,
   'mod'.$this->modname.'protologging' => 0,
   'mod'.$this->modname.'enable' => 0,
  ));
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->ready = TRUE;
  }
 }
 public function selectDB($name)
 {
  $this->dbname = $name;
  return TRUE;
 }
 public function getConnection($addr = NULL)
 {
  if (!$this->ready) {return FALSE;}
  if (empty($addr)) {$addr = Daemon::$settings['mod'.$this->modname.'server'];}
  if (isset($this->servConn[$addr]))
  {
   foreach ($this->servConn[$addr] as &$c)
   {
    if (isset($this->sessions[$c]) && !sizeof($this->sessions[$c]->callbacks)) {return $this->sessions[$c];}
   }
  }
  else {$this->servConn[$addr] = array();}
  $u = parse_url($addr);
  if (!isset($u['port'])) {$u['port'] = Daemon::$settings['mod'.$this->modname.'port'];}
  $connId = $this->connectTo($u['host'],$u['port']);
  if (!$connId) {return;}
  $this->sessions[$connId] = new MySQLClientSession($connId,$this);
  $this->sessions[$connId]->url = $addr;
  if (isset($u['user'])) {$this->sessions[$connId]->user = $u['user'];}
  if (isset($u['pass'])) {$this->sessions[$connId]->password = $u['pass'];}
  $this->servConn[$addr][] = $connId;
  return $this->sessions[$connId];
 }
}
class MySQLClientSession extends SocketSession
{
 public $url;
 public $seq = 0;
 public $clientFlags = 239237;
 public $maxPacketSize = 0x1000000;
 public $charsetNumber = 0x08;
 public $dbname = '';
 public $user = 'root';
 public $password = '';
 public $cstate = 0; // 0 - start, 1 - got initial packet, 2 - auth. packet sent, 3 - auth. error, 4 - handshaked OK
 public $instate = 0;
 public $resultRows = array();
 public $resultFields = array();
 public $callbacks = array();
 public $onConnected = NULL;
 public $authrozied = FALSE;
 public $context;
 public function init()
 {
 }
 public function onConnected($callback)
 {
  $this->onConnected = $callback;
  if ($this->cstate == 3) {call_user_func($callback,$this,FALSE);}
  elseif ($this->cstate === 4) {call_user_func($callback,$this,TRUE);}
 }
 public function bytes2int($str,$l = TRUE)
 {
  if ($l) {$str = strrev($str);}
  $dec = 0;
  $len = strlen($str);
  for($i = 0; $i < $len; ++$i) {$dec += ord(binarySubstr($str,$i,1))*pow(0x100,$len-$i-1);}
  return $dec;
 }
 function int2bytes($len,$int = 0,$l = TRUE)
 {
  $hexstr = dechex($int);
  if ($len === NULL) {if (strlen($hexstr) % 2) {$hexstr = "0".$hexstr;}}
  else {$hexstr = str_repeat('0',$len*2-strlen($hexstr)).$hexstr;}
  $bytes = strlen($hexstr)/2;
  $bin = '';
  for($i = 0; $i < $bytes; ++$i) {$bin .= chr(hexdec(substr($hexstr,$i*2,2)));}
  return $l?strrev($bin):$bin;
 }
 public function getPacketHeader()
 {
  if ($this->buflen < 4) {return FALSE;}
  return array($this->bytes2int(binarySubstr($this->buf,0,3)),ord(binarySubstr($this->buf,3,1))); // [length, seq]
 }
 public function sendPacket($packet)
 {
  $header = $this->int2bytes(3,strlen($packet)).chr($this->seq++); 
  $this->write($header);
  $this->write($packet);
  if (Daemon::$settings['mod'.$this->appInstance->modname.'protologging']) {Daemon::log('Client --> Server: '.Daemon::exportBytes($header.$packet)."\n\n");}
  return TRUE;
 }
 public function buildLenEncodedBinary($s)
 {
  if ($s === NULL) {return "\251";}
  $l = strlen($s);
  if ($l <= 250) {return chr($l).$s;}
  if ($l <= 0xFFFF) {return "\252".$this->int2bytes(2,$l).$s;}
  if ($l <= 0xFFFFFF) {return "\254".$this->int2bytes(3,$l).$s;}
  return $this->int2bytes(8,$l).$s;
 }
 public function parseEncodedBinary(&$s,&$p)
 {
  $f = ord(binarySubstr($s,$p,1));
  ++$p;
  if ($f <= 250) {return $f;}
  if ($s === 251) {return NULL;}
  if ($s === 255) {return FALSE;}
  if ($f === 252)
  {
   $o = $p;
   $p += 2;
   return $this->bytes2int(binarySubstr($s,$o,2));
  }
  if ($f === 253)
  {
   $o = $p;
   $p += 3;
   return $this->bytes2int(binarySubstr($s,$o,3));
  }
  $o = $p;
  $p =+ 8;
  return $this->bytes2int(binarySubstr($s,$o,8));
 }
 public function parseEncodedString(&$s,&$p)
 {
  $l = $this->parseEncodedBinary($s,$p);
  if (($l === NULL) || ($l === FALSE)) {return $l;}
  $o = $p;
  $p += $l;
  return binarySubstr($s,$o,$l);
 }
 public function getAuthToken($scramble,$password)
 {
  return sha1($scramble.sha1($hash1 = sha1($password,TRUE),TRUE),TRUE) ^ $hash1;
 }
 public function auth()
 {
  if ($this->cstate !== 1) {return;}
  ++$this->cstate;
  $this->callbacks[] = $this->onConnected;
  $this->clientFlags =
    MySQLClient::CLIENT_LONG_PASSWORD | 
    MySQLClient::CLIENT_LONG_FLAG | 
    MySQLClient::CLIENT_LOCAL_FILES | 
    MySQLClient::CLIENT_PROTOCOL_41 | 
    MySQLClient::CLIENT_INTERACTIVE | 
    MySQLClient::CLIENT_TRANSACTIONS | 
    MySQLClient::CLIENT_SECURE_CONNECTION | 
    MySQLClient::CLIENT_MULTI_STATEMENTS | 
    MySQLClient::CLIENT_MULTI_RESULTS;
  $this->sendPacket(
   pack('VVc',$this->clientFlags,$this->maxPacketSize,$this->charsetNumber)
   ."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
   .$this->user."\x00"
   .$this->buildLenEncodedBinary($this->getAuthToken($this->scramble,$this->password))
   .($this->dbname !== ''?$this->dbname."\x00":'')
  );
 }
 public function query($q,$callback = NULL)
 {
  return $this->command(MySQLClient::COM_QUERY,$q,$callback);
 }
 public function ping($callback = NULL)
 {
  return $this->command(MySQLClient::COM_PING,'',$callback);
 }
 public function command($cmd,$q = '',$callback = NULL)
 {
  if ($this->cstate !== 4) {return FALSE;}
  $this->callbacks[] = $callback;
  $this->seq = 0;
  $this->sendPacket(chr($cmd).$q);
 }
 public function stdin($buf)
 {
  $this->buf .= $buf;
  if (Daemon::$settings['mod'.$this->appInstance->modname.'protologging']) {Daemon::log('Server --> Client: '.Daemon::exportBytes($buf)."\n\n");}
  start:
  $this->buflen = strlen($this->buf);
  if (($packet = $this->getPacketHeader()) === FALSE) {return;}
  $this->seq = $packet[1]+1;
  if ($this->cstate === 0)
  {
   if ($this->buflen < 4+$packet[0]) {return;} // no whole packet yet
   $this->cstate = 1;
   $p = 4;
   $this->protover = ord(binarySubstr($this->buf,$p++,1));
   $this->serverver = '';
   while ($p < $this->buflen)
   {
    $c = binarySubstr($this->buf,$p++,1);
    if ($c === "\x00") {break;}
    $this->serverver .= $c;
   }
   $this->threadId = $this->bytes2int(binarySubstr($this->buf,$p,4));
   $p += 4;
   $this->scramble = binarySubstr($this->buf,$p,8);
   $p += 9;
   $this->serverCaps = $this->bytes2int(binarySubstr($this->buf,$p,2));
   $p += 2;
   $this->serverLang = ord(binarySubstr($this->buf,$p++,1));
   $this->serverStatus = $this->bytes2int(binarySubstr($this->buf,$p,2));
   $p += 2;
   $p += 13;
   $restScramble = binarySubstr($this->buf,$p,12);
   $this->scramble .= $restScramble;
   $p += 13;
   $this->auth();
  }
  else
  {
   if ($this->buflen < 4+$packet[0]) {return;} // not whole packet yet
   $p = 4;
   $fieldCount = ord(binarySubstr($this->buf,$p,1));
   $p += 1;
   if ($fieldCount === 0xFF) // Error packet
   {
    $u = unpack('v',binarySubstr($this->buf,$p,2));
    $p += 2;
    $this->errno = $u[1];
    $state = binarySubstr($this->buf,$p,6);
    $p =+ 6;
    $this->errmsg = binarySubstr($this->buf,$p,$packet[0]+4-$p);
    $this->onError();
   }
   elseif ($fieldCount === 0x00) // OK Packet Empty
   {
    if ($this->cstate === 2) {$this->cstate = 4;}
    $this->affectedRows = $this->parseEncodedBinary($this->buf,$p);

    $this->insertId = $this->parseEncodedBinary($this->buf,$p);

    $u = unpack('v',binarySubstr($this->buf,$p,2));
    $p += 2;
    $this->serverStatus = $u[1];
    
    $u = unpack('v',binarySubstr($this->buf,$p,2));
    $p += 2;
    $this->warnCount = $u[1];
    
    $this->message = binarySubstr($this->buf,$p,$packet[0]+4-$p);
    $this->onResultDone();
   }
   elseif ($fieldCount === 0xFE) // EOF Packet
   {
    ++$this->instate;
    if ($this->instate === 3) {$this->onResultDone();}
   }
   else // Data packet
   {
    --$p;
    if ($this->instate === 0) // Result Set Header Packet
    {
     $extra = $this->parseEncodedBinary($this->buf,$p);
     ++$this->instate;
    }
    elseif ($this->instate === 1) // Field Packet
    {
     $field = array();
     $field['catalog'] = $this->parseEncodedString($this->buf,$p);
     $field['db'] = $this->parseEncodedString($this->buf,$p);
     $field['table'] = $this->parseEncodedString($this->buf,$p);
     $field['org_table'] = $this->parseEncodedString($this->buf,$p);
     $field['name'] = $this->parseEncodedString($this->buf,$p);
     $field['org_name'] = $this->parseEncodedString($this->buf,$p);
     
     ++$p; // filler

     $u = unpack('v',binarySubstr($this->buf,$p,2));
     $p += 2;

     $field['charset'] = $u[1];
     $u = unpack('V',binarySubstr($this->buf,$p,4));
     $p += 4;
     $field['length'] = $u[1];
     
     $field['type'] = ord(binarySubstr($this->buf,$p,1));
     ++$p;
     
     $u = unpack('v',binarySubstr($this->buf,$p,2));
     $p += 2;
     $field['flags'] = $u[1];
     
     $field['decimals'] = ord(binarySubstr($this->buf,$p,1));
     ++$p;
     
     $this->resultFields[] = $field;
    }
    elseif ($this->instate === 2) // Row Packet
    {
     $row = array();
     for ($i = 0,$nf = sizeof($this->resultFields); $i < $nf; ++$i)
     {
      $row[$this->resultFields[$i]['name']] = $this->parseEncodedString($this->buf,$p);
     }
     $this->resultRows[] = $row;
    }
   }
  }
  $this->buf = binarySubstr($this->buf,4+$packet[0]);
  goto start;
 }
 public function onResultDone()
 {
  $this->instate = 0;
  $callback = array_shift($this->callbacks);
  if ($callback && is_callable($callback)) {call_user_func($callback,$this,TRUE);}
  $this->resultRows = array();
  $this->resultFields = array();
  if (Daemon::$settings['mod'.$this->appInstance->modname.'protologging']) {Daemon::log(__METHOD__);}
 }
 public function onError()
 {
  $this->instate = 0;
  $callback = array_shift($this->callbacks);
  if ($callback && is_callable($callback)) {call_user_func($callback,$this,FALSE);}
  $this->resultRows = array();
  $this->resultFields = array();

  if ($this->cstate === 2) // in case of auth error
  {
   $this->cstate = 3;
   $this->finish();
  }
  Daemon::log(__METHOD__.' #'.$this->errno.': '.$this->errmsg);
 }
 public function onFinish()
 {
  $this->finished = TRUE;
  unset($this->servConn[$this->url][$this->connId]);
  unset($this->appInstance->sessions[$this->connId]);
 }
}
class MySQLClientSessionFinished extends Exception {}
