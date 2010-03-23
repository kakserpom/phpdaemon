<?php
return new MongoClient;
class MongoClient extends AsyncServer
{
 public $sessions = array();
 public $servers = array();
 public $servConn = array();
 public $prefix = '';
 public $requests = array();
 public $cursors = array();
 public $lastReqId = 0;
 public $collections = array();
 public $dbname = '';
 const OP_REPLY = 1;
 const OP_MSG = 1000;
 const OP_UPDATE = 2001;
 const OP_INSERT = 2002;
 const OP_QUERY = 2004;
 const OP_GETMORE = 2005;
 const OP_DELETE = 2006;
 const OP_KILL_CURSORS = 2007;
 public $dtags_enabled = FALSE;
 public $cache;
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'servers' => '127.0.0.1',
   'mod'.$this->modname.'port' => 27017,
   'mod'.$this->modname.'prefix' => '',
   'mod'.$this->modname.'enable' => 0,
  ));
  $this->prefix = &Daemon::$settings['mod'.$this->modname.'prefix'];
  $this->cache = Daemon::$appResolver->getInstanceByAppName('MemcacheClient');
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $servers = explode(',',Daemon::$settings['mod'.$this->modname.'servers']);
   foreach ($servers as $s)
   {
    $e = explode(':',$s);
    $this->addServer($e[0],isset($e[1])?$e[1]:NULL);
   }
  }
 }
 public function selectDB($name)
 {
  $this->dbname = $name;
  return TRUE;
 }
 public function addServer($host,$port = NULL,$weight = NULL)
 {
  if ($port === NULL) {$port = Daemon::$settings['mod'.$this->modname.'port'];}
  $this->servers[$host.':'.$port] = $weight;
 }
 public function request($key,$opcode,$data,$reply = FALSE)
 {
  if ((is_object($key) && ($key instanceof MongoClientSession)))
  {
   $sess = $key;
   if ($sess->finished)
   {
    throw new MongoClientSessionFinished;
   }
  }
  else
  {
   $connId = $this->getConnectionByKey($key);
   $sess = $this->sessions[$connId];
  }
  $sess->write($p = pack('VVVV',strlen($data)+16,++$this->lastReqId,0,$opcode)
  .$data);
  //Daemon::log('p = '.Daemon::exportBytes($p));
  if ($reply) {$sess->busy = TRUE;}
  return $this->lastReqId;
 }
 public function find($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = 0;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (isset($p['tailable'])) {$p['opts'] += 2;}
  if (!isset($p['where'])) {$p['where'] = array();}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  if (isset($p['fields']) && is_string($p['fields']))
  {
   $e = explode(',',$p['fields']);
   $p['fields'] = array();
   foreach ($e as &$f) {$p['fields'][$f] = 1;}
  }
  if (is_string($p['where'])) {$p['where'] = new MongoCode($p['where']);}
  $o = array();
  $s = FALSE;
  foreach ($p as $k => $v)
  {
   if (($k === 'sort') || ($k === 'hint') || ($k === 'explain') || ($k === 'snapshot'))
   {
    if (!$s)
    {
     $s = TRUE;
    }
    if ($k === 'sort') {$o['orderby'] = $v;}
    elseif ($k === 'parse_oplog') {}
    else {$o[$k] = $v;}
   }
  }
  if ($s) {$o['query'] = $p['where'];}
  else {$o = $p['where'];}
  $bson = bson_encode($o);
  if (isset($p['parse_oplog']))
  {
   $bson = str_replace("\x11\$gt","\x09\$gt",$bson);
  }
  $reqId = $this->request($key,self::OP_QUERY,
   pack('V',$p['opts'])
   .$p['col']."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .$bson
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,FALSE,isset($p['parse_oplog']));
 }
 public function findOne($p,$callback,$key = '')
 {
  if (isset($p['cachekey']))
  {
   $db = $this;
   $this->cache->get($p['cachekey'],function($r) use ($db, $p, $callback, $key)
   {
    if ($r->result !== NULL)
    {
     call_user_func($callback,bson_decode($r->result));
    }
    else
    {
     unset($p['cachekey']);
     $db->findOne($p,$callback,$key);
    }
   });
   return;
  }
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['where'])) {$p['where'] = array();}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  if (isset($p['fields']) && is_string($p['fields']))
  {
   $e = explode(',',$p['fields']);
   $p['fields'] = array();
   foreach ($e as &$f) {$p['fields'][$f] = 1;}
  }
  if (is_string($p['where'])) {$p['where'] = new MongoCode($p['where']);}
  $o = array();
  $s = FALSE;
  foreach ($p as $k => $v)
  {
   if (($k === 'sort') || ($k === 'hint') || ($k === 'explain') || ($k === 'snapshot'))
   {
    if (!$s)
    {
     $s = TRUE;
    }
    if ($k === 'sort') {$o['orderby'] = $v;}
    elseif ($k === 'parse_oplog') {}
    else {$o[$k] = $v;}
   }
  }
  if ($s) {$o['query'] = $p['where'];}
  else {$o = $p['where'];}
  $reqId = $this->request($key,self::OP_QUERY,
   pack('V',$p['opts'])
   .$p['col']."\x00"
   .pack('VV',$p['offset'],-1)
   .bson_encode($o)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,TRUE);
 }
 public function count($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['where'])) {$p['where'] = array();}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  $e = explode('.',$p['col']);
  $query = array(
   'count' => $e[1],
   'query' => $p['where'],
   'fields' => array('_id' => 1),
  );
  if (is_string($p['where'])) {$query['where'] = new MongoCode($p['where']);}
  elseif (is_object($p['where']) || sizeof($p['where'])) {$query['query'] = $p['where'];}
  $packet = pack('V',$p['opts'])
   .$e[0].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,TRUE);
 }
 public function min($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['where'])) {$p['where'] = array();}
  if (!isset($p['max'])) {$p['max'] = array();}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  $e = explode('.',$p['col']);
  $query = array(
   '$min' => $p['min'],
   'query' => $p['where'],
  );
  if (is_string($p['where'])) {$query['where'] = new MongoCode($p['where']);}
  elseif (is_object($p['where']) || sizeof($p['where'])) {$query['query'] = $p['where'];}
  $packet = pack('V',$p['opts'])
   .$e[0].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,TRUE);
 }
 public function evaluate($code,$callback,$key = '')
 {
  $p = array();
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['db'])) {$p['db'] = $this->dbname;}
  $query = array('$eval' => new MongoCode($code));
  $packet = pack('V',$p['opts'])
   .$p['db'].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['db'],$callback,TRUE);
 }
 public function max($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['where'])) {$p['where'] = array();}
  if (!isset($p['max'])) {$p['max'] = array();}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  $e = explode('.',$p['col']);
  $query = array(
   '$max' => $p['max'],
   'query' => $p['where'],
  );
  if (is_string($p['where'])) {$query['where'] = new MongoCode($p['where']);}
  elseif (is_object($p['where']) || sizeof($p['where'])) {$query['query'] = $p['where'];}
  $packet = pack('V',$p['opts'])
   .$e[0].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,TRUE);
 }
 public function distinct($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['key'])) {$p['key'] = '';}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  $e = explode('.',$p['col']);
  $query = array(
   'distinct' => $e[1],
   'key' => $p['key'],
  );
  $packet = pack('V',$p['opts'])
   .$e[0].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,TRUE);
 }
 public function group($p,$callback,$key = '')
 {
  if (!isset($p['offset'])) {$p['offset'] = 0;}
  if (!isset($p['limit'])) {$p['limit'] = -1;}
  if (!isset($p['opts'])) {$p['opts'] = 0;}
  if (!isset($p['reduce'])) {$p['reduce'] = '';}
  if (is_string($p['reduce'])) {$p['reduce'] = new MongoCode($p['reduce']);}
  if (strpos($p['col'],'.') === FALSE) {$p['col'] = $this->dbname.'.'.$p['col'];}
  $e = explode('.',$p['col']);
  $query = array(
   'group' => array(
     'ns' => $e[1],
     'key' => $p['key'],
     '$reduce' => $p['reduce'],
     'initial' => $p['initial'],
    )
  );
  if (isset($p[$k = 'cond'])) {$query['group'][$k]= $p[$k];}
  if (isset($p[$k = 'finalize']))
  {
   if (is_string($p[$k])) {$p[$k] = new MongoCode($p[$k]);}
   $query['group'][$k] = $p[$k];
  }
  if (isset($p[$k = 'keyf'])) {$query[$k] = $p[$k];}
  $packet = pack('V',$p['opts'])
   .$e[0].'.$cmd'."\x00"
   .pack('VV',$p['offset'],$p['limit'])
   .bson_encode($query)
   .(isset($p['fields'])?bson_encode($p['fields']):'')
  ;
  $reqId = $this->request($key,self::OP_QUERY,$packet,TRUE);
  $this->requests[$reqId] = array($p['col'],$callback,FALSE);
 }
 public function update($col,$cond,$data,$flags = 0,$key = '')
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  if (is_string($cond)) {$cond = new MongoCode($cond);}
  if ($flags & 1 == 1)
  {
   //if (!isset($data['_id'])) {$data['_id'] = new MongoId();}
  }
  $reqId = $this->request($key,self::OP_UPDATE,
   "\x00\x00\x00\x00"
   .$col."\x00"
   .pack('V',$flags)
   .bson_encode($cond)
   .bson_encode($data)
  );
 }
 public function updateMulti($col,$cond,$data,$key = '')
 {
  return $this->update($col,$cond,$data,2,$key);
 }
 public function upsert($col,$cond,$data,$multi = FALSE,$key = '')
 {
  return $this->update($col,$cond,$data,$multi?3:1,$key);
 }
 public function insert($col,$doc = array(),$key = '')
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  if (!isset($doc['_id'])) {$doc['_id'] = new MongoId();}
  $reqId = $this->request($key,self::OP_INSERT,
   "\x00\x00\x00\x00"
   .$col."\x00"
   .bson_encode($doc)
  );
  return $doc['_id'];
 }
 public function killCursors($cursors = array(),$key = '')
 {
  $reqId = $this->request($key,self::OP_INSERT,
   "\x00\x00\x00\x00"
   .pack('V',sizeof($cursors))
   .implode('',$cursors)
  );
 }
 public function insertMulti($col,$docs = array(),$key = '')
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  $ids = array();
  $bson = '';
  foreach ($docs as &$doc)
  {
   if (!isset($doc['_id'])) {$doc['_id'] = new MongoId();}
   $bson .= bson_encode($doc);
   $ids[] = $doc['_id'];
  }
  $reqId = $this->request($key,self::OP_INSERT,
   "\x00\x00\x00\x00"
   .$col."\x00"
   .$bson
  );
  return $ids;
 }
 public function remove($col,$cond = array(),$key = '')
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  if (is_string($cond)) {$cond = new MongoCode($cond);}
  $reqId = $this->request($key,self::OP_DELETE,
   "\x00\x00\x00\x00"
   .$col."\x00"
   ."\x00\x00\x00\x00"
   .bson_encode($cond)
  );
 }
 public function getMore($col,$id,$number,$key = '')
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  $reqId = $this->request($key,self::OP_GETMORE,
   "\x00\x00\x00\x00"
   .$col."\x00"
   .pack('V',$number)
   .$id
  );
 }
 public function getCollection($col)
 {
  if (strpos($col,'.') === FALSE) {$col = $this->dbname.'.'.$col;}
  if (isset($this->collections[$col])) {return $this->collections[$col];}
  return $this->collections[$col] = new MongoClientCollection($col,$this);
 }
 public function __get($name)
 {
  return $this->getCollection($name);
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
  }
 }
 public function getConnection($addr)
 {
  if (isset($this->servConn[$addr]))
  {
   foreach ($this->servConn[$addr] as &$c)
   {
    if (isset($this->sessions[$c]) && !$this->sessions[$c]->busy) {return $c;}
   }
  }
  else {$this->servConn[$addr] = array();}
  $e = explode(':',$addr);
  $connId = $this->connectTo($e[0],$e[1]);
  $this->sessions[$connId] = new MongoClientSession($connId,$this);
  $this->sessions[$connId]->addr = $addr;
  $this->servConn[$addr][] = $connId;
  return $connId;
 }
 private function getConnectionByKey($key)
 {
  if (($this->dtags_enabled) && (($sp = strpos($name,'[')) !== FALSE) && (($ep = strpos($name,']')) !== FALSE) && ($ep > $sp))
  {
   $key = substr($key,$sp+1,$ep-$sp-1);
  }
  srand(crc32($key));
  $addr = array_rand($this->servers);
  srand();  
  return $this->getConnection($addr);
 }
}
class MongoClientSession extends SocketSession
{
 public $busy = FALSE;
 public function stdin($buf)
 {
  $this->buf .= $buf;
  start:
  $l = strlen($this->buf);
  if ($l < 16) {return;} // we have not enough data yet
  $h = unpack('Vlen/VreqId/VresponseTo/VopCode',binarySubstr($this->buf,0,16));
  $plen = (int) $h['len'];
  if ($plen > $l) {return;} // we have not enough data yet
  if ($h['opCode'] === MongoClient::OP_REPLY)
  {
   $r = unpack('Vflag/VcursorID1/VcursorID2/Voffset/Vlength',binarySubstr($this->buf,16,20));
   $r['cursorId'] = binarySubstr($this->buf,20,8);
   $id = (int) $h['responseTo'];
   $cur = ($r['cursorId'] !== "\x00\x00\x00\x00\x00\x00\x00\x00"?'c'.$r['cursorId']:'r'.$h['responseTo']);
   if (isset($this->appInstance->requests[$id][2]) && ($this->appInstance->requests[$id][2] === FALSE) && !isset($this->appInstance->cursors[$cur]))
   {
    $this->appInstance->cursors[$cur] = new MongoClientCursor($cur,$this->appInstance->requests[$id][0],$this);
    $this->appInstance->cursors[$cur]->callback = $this->appInstance->requests[$id][1];
    $this->appInstance->cursors[$cur]->parseOplog = isset($this->appInstance->requests[$id][3]) && $this->appInstance->requests[$id][3];
   }
   if (isset($this->appInstance->cursors[$cur]) && (($r['length'] === 0) || (binarySubstr($cur,0,1) === 'r')))
   {
    $this->appInstance->cursors[$cur]->finished = TRUE;
   }
   $p = 36;
   while ($p < $plen)
   {
    $dl = unpack('Vlen',binarySubstr($this->buf,$p,4));
    $doc = bson_decode(binarySubstr($this->buf,$p,$dl['len']));
    if (isset($this->appInstance->cursors[$cur]) && $this->appInstance->cursors[$cur]->parseOplog && isset($doc['ts']))
    {
     $tsdata = unpack('Vsec/Vinc',binarySubstr($this->buf,$p+1+4+3,8));
     $doc['ts'] = $tsdata['sec'].' '.$tsdata['inc'];
    }
    $this->appInstance->cursors[$cur]->items[] = $doc;
    $p += $dl['len'];
   }
   $this->busy = FALSE;
   if (isset($this->appInstance->requests[$id][2]) && $this->appInstance->requests[$id][2])
   {
    call_user_func($this->appInstance->requests[$id][1],isset($this->appInstance->cursors[$cur]->items[0])?$this->appInstance->cursors[$cur]->items[0]:FALSE);
    if (isset($this->appInstance->cursors[$cur]) && ($this->appInstance->cursors[$cur] instanceof MongoClientCursor)) {$this->appInstance->cursors[$cur]->destroy();}
   }
   elseif (isset($this->appInstance->cursors[$cur]))
   {
    call_user_func($this->appInstance->cursors[$cur]->callback,$this->appInstance->cursors[$cur]);
   }
   unset($this->appInstance->requests[$id]);
  }
  $this->buf = binarySubstr($this->buf,$plen);
  goto start;
 }
 public function onFinish()
 {
  $this->finished = TRUE;
  unset($this->servConn[$this->addr][$this->connId]);
  unset($this->appInstance->sessions[$this->connId]);
 }
}
class MongoClientCollection
{
 public $appInstance;
 public $name;
 public function __construct($name,$appInstance)
 {
  $this->name = $name;
  $this->appInstance = $appInstance;
 }
 public function find($callback,$p = array())
 {
  $p['col'] = $this->name;
  return $this->appInstance->find($p,$callback);
 }
 public function findOne($callback,$p = array())
 {
  $p['col'] = $this->name;
  return $this->appInstance->findOne($p,$callback);
 }
 public function count($callback,$where = array())
 {
  return $this->appInstance->count(array('col' => $this->name, 'where' => $where),$callback);
 }
 public function group($callback,$p = array())
 {
  $p['col'] = $this->name;
  return $this->appInstance->group($p,$callback);
 }
 public function insert($doc,$key = '')
 {
  return $this->appInstance->insert($this->name,$doc,$key);
 }
 public function insertMulti($docs,$key = '')
 {
  return $this->appInstance->insertMulti($this->name,$docs,$key);
 }
 public function update($cond,$data,$flags = 0,$key = '')
 {
  return $this->appInstance->update($this->name,$cond,$data,$flags,$key);
 }
 public function updateMulti($cond,$data,$key = '')
 {
  return $this->appInstance->updateMulti($this->name,$cond,$data,$key);
 }
 public function upsert($cond,$data,$key = '')
 {
  return $this->appInstance->upsert($this->name,$cond,$data,$key);
 }
 public function remove($cond = array(),$key = '')
 {
  return $this->appInstance->remove($this->name,$cond,$key);
 }
}
class MongoClientCursor
{
 public $id;
 public $appInstance;
 public $col;
 public $items = array();
 public $item;
 public $session;
 public $finished = FALSE;
 public function __construct($id,$col,$session)
 {
  $this->id = $id;
  $this->col = $col;
  $this->session = $session;
  $this->appInstance = $session->appInstance;
 }
 public function getMore($number = 0)
 {
  if (binarySubstr($this->id,0,1) === 'c') {$this->appInstance->getMore($this->col,binarySubstr($this->id,1),$number,$this->session);}
  return TRUE;
 }
 public function destroy()
 {
  unset($this->appInstance->cursors[$this->id]);
  return TRUE;
 }
 public function __destruct()
 {
  if (binarySubstr($this->id,0,1) === 'c') {$this->appInstance->killCursors(array(binarySubstr($this->id,1)));}
 }
}
class MongoClientSessionFinished extends Exception {}
