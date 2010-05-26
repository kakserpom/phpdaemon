<?php
// DRAFT
/*
 MongoDB indexes:
 
  db.btswarm.ensureIndex({hash: 1, peer: 1},{unique: true});
  db.btswarm.ensureIndex({ltime: -1});
  db.btswarm.ensureIndex({key: 1, uk: 1});

  db.btorrents.ensureIndex({hash: 1},{unique: true});
  db.btorrents.ensureIndex({"attrs.title": 1});
  db.btorrents.ensureIndex({"info.name": 1});
  db.btorrents.ensureIndex({"info.length": 1});
  db.btsearchhistory.ensureIndex({"uid": 1, "q": 1},{unique: true});
  db.btsearchhistory.ensureIndex({"timestamp": -1});
  
  db.btuserhistory.ensureIndex({"uid": 1, "hash": 1,"timestamp": -1});
  
  db.btstat.ensureIndex({hash: 1, uk: 1, peer: 1},{unique: true});
  db.btstat.ensureIndex({uk: 1});
 
  db.btusers.ensureIndex({uk: 1},{unique: true});
  db.btusers.ensureIndex({email: 1},{unique: true});
  
  db.btcomments.ensureIndex({hash: 1, uid: 1, timestamp: -1});

@TODO:

 1. local peers interconnect.

*/
return new BitTorrentTracker;
class BitTorrentTracker extends AppInstance
{
 public $db;
 public $LockClient;
 public $filestreams = array();
 public $filestreamsCounter = 0;
 public static function isEmail($email) {return preg_match('~^[a-z0-9_\-\.]+@[a-z0-9_\-\.]+$~i',$email) > 0;}
 public static function generateUK()
 {
  static $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $fp = fopen('/dev/urandom','rb');
  $buf = pack('V',time()).pack('V',ip2long($_SERVER['REMOTE_ADDR'])).pack('V',getmypid()).fread($fp,20);
  fclose($fp);
  $uk = '';
  for ($i = 0; $i < 32; ++$i) {$uk .= $chars[ord($buf[$i]) % 62];}
  return $uk;
 }
 public static function bencode($m)
 {
  if (is_object($m))
  {
   if ($m instanceof BitTorrentTracker_bencode_mutable)
   {
    return (string) $m;
   }
   $m = (array) $m;
   ksort($m);
   $s = 'd';
   foreach ($m as $k => $v) {$s .= self::bencode($k).self::bencode($v);}
   return $s.'e';
  }
  elseif (is_array($m))
  {
   $a = array_keys($m);
   for($i = 0, $t = sizeof($a); $i < $t; ++$i) {if ($a[$i] != $i)  {break;}}
   if ($i < $t)
   {
    ksort($m);
    $s = 'd';
    foreach ($m as $k => $v) {$s .= self::bencode($k).self::bencode($v);}
    return $s.'e';
   }
   else
   {
    $s = 'l';
    foreach ($m as $v) {$s .= self::bencode($v);}
    return $s.'e';
   }
  }
  elseif (is_int($m) || is_float($m))
  {
   return 'i'.((string)$m).'e';
  }
  elseif (is_string($m))
  {
   return strlen($m).':'.$m;
  }
 }
 public function init()
 {
  if (!isset(Daemon::$settings[$k = 'mod'.$this->modname.'enable'])) {Daemon::$settings[$k] = 0;}
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->db = Daemon::$appResolver->getInstanceByAppName('MongoClient');
   $this->db->selectDB('main');
   $this->LockClient = Daemon::$appResolver->getInstanceByAppName('LockClient');
   Daemon::log(__CLASS__.' up.');
  }
 }
 public function addTorrentFromString($str,$properties = array())
 {
  $a = BDecode::fromString($str);
  if (!isset($a['info'])) {throw new Exception('\'info\' doesn\'t exist.');}
  $a['hash'] = sha1(BitTorrentTracker::bencode($a['info']));
  foreach ($a as $k => $v)
  {
   if ((substr($k,0,1) === '$')
   || ($k === 'downloads')
   || ($k === '_id')
   ) {unset($a[$k]);}
  }
  if (isset($a['info']['pieces']))
  {
   $a['info']['pieces'] = new MongoBinData($a['info']['pieces']);
  }
  if (isset($a['info']['length']))
  {
   $a['length'] = $a['info']['length'];
  }
  elseif (isset($a['info']['files']))
  {
   $a['length'] = (float) 0;
   foreach ($a['info']['files'] as $f) {$a['length'] += (float) $f['length'];}
  }
  else
  {
   $a['length'] = 0;
  }
  foreach ($properties as $k => $v)
  {
   $a[$k] = $v;
  }
  Daemon::log($a['publisher-url']);
  $this->db->btorrents->upsert(
   array('hash' => $a['hash']),
   array(
    '$set' => $a,
  ));
 }
 public function addTorrentFromFile($fn,$properties = array())
 {
  try
  {
   $appInstance = $this;
   $size = filesize($fn);
   $n = ++$appInstance->filestreamsCounter;
   $appInstance->filestreams[$n] = new AsyncStream(fopen($fn,'r'));
   $appInstance->filestreams[$n]
   ->onReadData(function($stream,$data) use ($size)
   {
    $stream->buf .= $data;
    if (strlen($stream->buf) >= $size) {$stream->onEofEvent();}
   })
   ->onEOF(function($stream) use ($appInstance, $properties, $n)
   {
    try
    {
     $appInstance->addTorrentFromString($stream->buf,$properties);
     $stream->buf = '';
     unset($appInstance->filestreams[$n]);
    }
    catch (Exception $e) {Daemon::log(__METHOD__.': '.$e->getMessage());}
   })
   ->enable();
  }
  catch (BadStreamDescriptorException $e)
  {
  }
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->pushRequest(new BitTorrentTracker_RatingUpdate($this,$this));
   $this->pushRequest(new BitTorrentTracker_TorrentImporter($this,$this));
  }
 }
 public function beginRequest($req,$upstream) {return new BitTorrentTracker_Request($this,$upstream,$req);}
}
class BitTorrentTracker_Request extends Request
{
 public $eState = '';
 public $action = '';
 public $timeout = 2;
 public $reqInterval = 10;
 public $reqMinInterval = 10;
 public $trackerId = 1;
 public $completeNum = array();
 public $incompleteNum = array();
 public $fileInfo = array();
 public $peers = '';
 public $jobDone = 0;
 public $jobTotal = 0;
 public function init()
 {
  $this->header('X-Tracker: TrueBTT');
  $this->header('Content-Type: text/plain; charset=utf-8');
 }
 public function __destruct()
 {
 // Daemon::log(get_class($this).' destructed ('.$this->attrs->server['REQUEST_URI'].')');
 }
 public function countLeeches($hash)
 {
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  ++$this->jobTotal;
  $this->appInstance->db->btswarm->count(function($result) use ($appInstance, $reqID, $hash) // count leeches.
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   $req->incompleteNum[$hash] = $result['n'];
   ++$req->jobDone;
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
     'hash' => $hash,
     'ltime' => array('$gt' => time()-600),
     'left' => array('$gt' => 0),
  ));
 }
 public function countSeeds($hash)
 {
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  ++$this->jobTotal;
  $this->appInstance->db->btswarm->count(function($result) use ($appInstance, $reqID, $hash) // count seeds.
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   $req->completeNum[$hash] = $result['n'];
   ++$req->jobDone;
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
      'hash' => $hash,
      'ltime' => array('$gt' => time()-600),
      'left' => 0,
  ));
 }
 public function getTorrentByHash($hash)
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btorrents->findOne(function($item) use ($appInstance, $reqID, $hash)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   ++$req->jobDone;
   $req->fileInfo[$hash] = $item;
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
   'where' => array('hash' => $hash),
  ));
 }
 public function getUserByEmail($email)
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btusers->findOne(function($item) use ($appInstance, $reqID)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   ++$req->jobDone;
   if ($item['uk'] instanceof MongoBinData) {$item['uk'] = $item['uk']->bin;}
   $req->user = $item;
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
   'where' => array('email' => (string) $email),
  ));
 }
 public function getUserById($id,$callback = NULL)
 {
  ++$this->jobTotal;
  if (!$id instanceof MongoId) {$id = new MongoId($id);}
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btusers->findOne(function($item) use ($appInstance, $reqID, $callback)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   ++$req->jobDone;
   if ($item['uk'] instanceof MongoBinData) {$item['uk'] = $item['uk']->bin;}
   $req->user = $item;
   if ($callback) {call_user_func($callback);}
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
   'where' => array('_id' => $id),
  ));
 }
 public function getUserByUK($uk,$callback = NULL)
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btusers->findOne(function($item) use ($appInstance, $reqID, $callback)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   ++$req->jobDone;
   if ($item['uk'] instanceof MongoBinData) {$item['uk'] = $item['uk']->bin;}
   $req->user = $item;
   if ($callback) {call_user_func($callback);}
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
   'where' => array('uk' => $uk),
  ));
 }
 public function search($params,$o)
 {
  ++$this->jobTotal;
  $this->stime = microtime(TRUE);
  if (isset($params['limit'])) {$limit = (int) $params['limit'];}
  else {$limit = 20;}
  if (isset($params['offset'])) {$offset = (int) $params['offset'];}
  else {$offset = 0;}
  $sort = array('info.length' => -1);
  $q = (isset($params['q']) && is_string($params['q']))?$params['q']:'';
  $where = array(
   'attrs.title' => new MongoRegex('/'.$q.'/i'),
  );
  $req->search = array(
   'results' => array(),
   'total' => 0,
  );
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btorrents->find(function($cursor) use ($appInstance, $reqID, $q, $o)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   foreach ($cursor->items as $k => $v)
   {
    $req->search['results'][] = $v;
    unset($cursor->items[$k]);
   }
   if (!$cursor->finished) {$cursor->getMore();}
   else
   {
    $req->appInstance->db->btsearchhistory->upsert(array(
     'q' => $q,
     'uid' => $o['uid']
    ),
    array(
     'q' => $q,
     'uid' => $o['uid'],
     'timestamp' => time(),
    ));
    $req->search['took'] = round(microtime(TRUE)-$req->stime,5);
    ++$req->jobDone;
    if ($req->jobDone >= $req->jobTotal)
    {
     $req->eState = 'done';
     $req->wakeup();
    }
    $cursor->destroy();
   }
  },array(
   'sort' => $sort,
   'fields' => 'info.name,hash,length,downloads,attrs,publisher-url',
   'offset' => $offset,
   'limit' => min($limit,10),
   'where' => $where
  ));
 }
 public function getSearchHistory($o)
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->searchhistory = array();
  $this->appInstance->db->btsearchhistory->find(function($cursor) use ($appInstance, $reqID)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   foreach ($cursor->items as $k => $v)
   {
    $req->searchhistory[] = $v;
    unset($cursor->items[$k]);
   }
   if (!$cursor->finished) {$cursor->getMore();}
   else
   {
    ++$req->jobDone;
    if ($req->jobDone >= $req->jobTotal)
    {
     $req->eState = 'done';
     $req->wakeup();
    }
    $cursor->destroy();
   }
  },array(
   'sort' => array('timestamp' => -1),
   'where' => array('uid' => $o['uid'],),
   'limit' => -10,
  ));
 }
 public function findPeers()
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $req = $this;
  $where = array(
       'hash' => $this->q['hash'],
       'ltime' => array('$gt' => time()-600)
  );
  if ($this->q['left'] == 0) {$where['left'] = array('$gt' => 0);} // we shall not connect seed to seed. 
  else {$where['peer'] = array('$ne' => new MongoBinData($this->q['peer']));} // else we shall not connect this peer to himself.
  if (isset($_GET['debug'])) {var_dump($where);}
  $this->appInstance->db->btswarm->find(function($cursor) use ($appInstance, $reqID) // find the peers.
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   if (isset($req->attrs->request['debug'])) {Daemon::log('debug swarm - '.Daemon::var_dump($cursor->items));}
   foreach ($cursor->items as $k => $v)
   {
    Daemon::log('Peer '.$req->q['ip'].':'.$req->q['port'].' ('.$req->q['client'].') recived peer '.$v['ip'].':'.$v['port'].' ('.$v['client'].')');
    if ($req->q['compact'])
    {
     $req->peers .= pack('Nn',ip2long($v['ip']),$v['port']);
    }
    else
    {
     $o = new stdClass;
     if (!$req->q['no_peer_id']) {$o->peerid = $v['peer'];}
     $o->ip = $v['ip'];
     $o->port = $v['port'];
     $req->peers .= BitTorrentTracker::bencode($o); 
    }
    unset($cursor->items[$k]);
   }
   if (!$cursor->finished) {$cursor->getMore();}
   else
   {
    if ($req->q['compact'])
    {
     $req->peers = BitTorrentTracker::bencode($req->peers);
    }
    else
    {
     $req->peers = 'l'.$req->peers.'e';
    }
    ++$req->jobDone;
    if ($req->jobDone >= $req->jobTotal)
    {
     $req->eState = 'done';
     $req->wakeup();
    }
    $cursor->destroy();
   }
  },array(
   'sort' => $req->q['superseed']?array('uploaded' => -1):array('ltime' => -1),
   'limit' => $req->q['numwant']?:200,
   'where' => $where
  ));
 }
 public function byAuthKey($key,$callback)
 {
  ++$this->jobTotal;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $this->appInstance->db->btauthkeys->findOne(function($item) use ($appInstance, $reqID, $key, $callback)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   call_user_func($callback,$item);
   ++$req->jobDone;
   if ($req->jobDone >= $req->jobTotal)
   {
    $req->eState = 'done';
    $req->wakeup();
   }
  },array(
   'where' => array('_id' => new MongoId($key)),
  ));
 }
 public function run()
 {
  $req = $this;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  if ($req->eState === '')
  {
   //Daemon::log('BitTorrent Tracker: request: '.$_SERVER['REQUEST_URI']);
  }
  $req->action = substr(strrchr($_SERVER['SCRIPT_NAME'],'/'),1);
  if ($req->eState === 'inprogress') // we timed out
  {
   $req->header('503 Service Temporary Unavailable');
   if (isset($_REQUEST['json']))
   {
    echo json_encode(array('error' => 503, 'errmsg' => '503 Service Temporary Unavailable'));
   }
   else
   {
    echo ':-( Perhaps that we\'re out of capacity.. we\'re sorry, try again shortly later.';
   }
   return Request::DONE;
  }
  if ($req->action === 'signin')
  {
   if ($req->eState === '')
   {
    $req->eState = 'inprogress';
    $req->getUserByEmail(self::getString($_REQUEST['email']));
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    $r = array();
    if (!$req->user)
    {
     if (isset($_REQUEST['register']))
     {
      if (!BitTorrentTracker::isEmail(self::getString($_REQUEST['email'])))
      {
       $r['error'] = 'incorrectEmail';
      }
      else
      {
       $uid = $req->appInstance->db->btusers->insert($r['user'] = array('uk' => BitTorrentTracker::generateUK(),
        'email' => self::getString($_REQUEST['email']),
        'password' => self::getString($_REQUEST['password']),
        'regip' => $_SERVER['REMOTE_ADDR'],
        'regdate' => time(),
        'uploaded' => 0,
        'downloded' => 0,
       ));
       unset($r['user']['password']);
       $r['authkey'] = (string) $req->appInstance->db->btauthkeys->insert(array('uid' => $uid));
       $r['result'] = 'registered';
      }
     }
     else
     {
      $r['error'] = 'notfound';
     }
    }
    elseif ($req->user['password'] != self::getString($_REQUEST['password'])) {$r['error'] = 'badpassword';}
    else
    {
     $r['result'] = 'logged';
     $r['user'] = $req->user;
     $r['authkey'] = (string) $req->appInstance->db->btauthkeys->insert(array(
      'uid' => $req->user['_id'],
      'ts' => time(),
      'ip' => $_SERVER['REMOTE_ADDR'],
     ));
    }
    echo json_encode($r);
    return Request::DONE;
   }
  }
  elseif ($req->action === 'signout')
  {
   if ($req->eState === '')
   {
    $req->appInstance->db->btauthkeys->remove(array('_id' => self::getString($_REQUEST['authkey'])));
    echo json_encode(array('result' => 'ok'));
    return;
   }
  }
  elseif ($req->action === 'userinfo')
  {
   if ($req->eState === '')
   {
    $req->eState = 'inprogress';
    $req->getUserById(self::getString($_REQUEST['id']));
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    if ($req->user) {unset($req->user['password']);}
    echo json_encode(array('user' => $req->user));
    return Request::DONE;
   }
  }
  elseif ($req->action === 'getStatus')
  {
   if ($req->eState === '')
   {
    $req->eState = 'inprogress';
    $req->byAuthkey(self::getString($_REQUEST['authkey']),function($o) use ($appInstance, $reqID)
    {
     if (!isset($appInstance->queue[$reqID])) {return;}
     $req = $appInstance->queue[$reqID];
     if ($o) {$req->getUserById($o['uid']);}
    });
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    if (isset($req->user)) {unset($req->user['password']);}
    echo json_encode(array('user' => isset($req->user)?$req->user:NULL));
    return Request::DONE;
   }
  }
  elseif ($req->action === 'search')
  {
   if ($req->eState === '')
   {
    $req->eState = 'inprogress';
    $req->byAuthkey(self::getString($_REQUEST['authkey']),function($o) use ($appInstance, $reqID)
    {
     if (!isset($appInstance->queue[$reqID])) {return;}
     $req = $appInstance->queue[$reqID];
     if ($o)
     {
      $req->search($_REQUEST,$o);
      $req->getSearchHistory($o);
     }
    });
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    $req->search['history'] = $req->searchhistory;
    echo json_encode($req->search);
    return Request::DONE;
   }
  }
  elseif ($req->action === 'getTorrentFile')
  {
   $hash = self::getString($_GET['info_hash']);
   if ($req->eState === '')
   {
    if ($hash === '')
    {
     $req->eState = 'done';
    }
    else
    {
     $req->getUserByUK(self::getString($_REQUEST['uk']),function() use ($appInstance, $reqID, $hash)
     {
      if (!isset($appInstance->queue[$reqID])) {return;}
      $req = $appInstance->queue[$reqID];
      if ($req->user) {$req->getTorrentByHash($hash);}
     });
     $req->eState = 'inprogress';
     $req->sleep($req->timeout);
    }
   }
   if ($req->eState === 'done')
   {
    if (!$req->user)
    {
     $this->header('404 Not Found');
     echo 'You should log in.';
     return Request::DONE;
    }
    if (($hash === '') || (!isset($req->fileInfo[$hash])))
    {
     $this->header('404 Not Found');
     echo 'Sorry, I\'ve not recognized a torrent with the requested info-hash.';
     return Request::DONE;
    }
    $t = $req->fileInfo[$hash];
    $req->appInstance->db->btuserhistory->upsert(array(
     'hash' => $hash,
     'uid' => $req->user['_id'],
    ),
    array(
     'hash' => $hash,
     'uid' => $req->user['_id'],
     'timestamp' => time(),
    ));
    $this->header('Content-Transfer-Encoding: binary');
    if (!isset($_GET['plain']))
    {
     $this->header('Content-Disposition: attachment; filename='.urlencode($hash).'.torrent');
     $this->header('Content-Type: application/octet-stream');
    }
    else
    {
     $this->header('Content-Type: text/plain');
    }
    unset(
     $t['_id'],
     $t['downloads'],
     $t['attrs']
    );
    if (isset($t['info']['pieces']) && ($t['info']['pieces'] instanceof MongoBinData))
    {
     $t['info']['pieces'] = $t['info']['pieces']->bin;
    }
    $announce = array();
    $announce[] = 'http://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:$_SERVER['SERVER_ADDR']).'/announce?uk='.urlencode($req->user['uk']);
    $t['announce'] = implode("\r\n\r\n",$announce);
    $t['announce-list'] = $announce;
    if (isset($_GET['json'])) {echo json_encode($t);}
    else {echo BitTorrentTracker::bencode($t);}
    return Request::DONE;
   }
  }
  elseif ($req->action === 'announce')
  {
   if ($req->eState === '')
   {
    if (strpos($_SERVER['QUERY_STRING'],'&?') !== FALSE)
    {
     parse_str(strtr($_SERVER['QUERY_STRING'],array('&?' => '&')),$_GET);
    }
    $req->q = array(
     'uk' => self::getString($_GET['uk']),
     'hash' => self::getString($_GET['info_hash']),
     'peer' => self::getString($_GET['peer_id']),
     'port' => self::getString($_GET['port']),
     'uploaded' => self::getString($_GET['uploaded']),
     'downloaded' => self::getString($_GET['downloaded']),
     'left' => self::getString($_GET['left']),
     'compact' => self::getString($_GET['compact']) === '1',
     'no_peer_id' => self::getString($_GET['no_peer_id']) === '1',
     'event' => self::getString($_GET['event']),
     'ip' => self::getString($_GET['ip'])?:$_SERVER['REMOTE_ADDR'],
     'numwant' => (int) self::getString($_GET['numwant']),
     'superseed' => (int) self::getString($_GET['superseed']) === '1',
     'key' => self::getString($_GET['key']),
     'uk' => self::getString($_GET['uk']),
     'trackerid' => self::getString($_GET['trackerid']),
     'client' => self::getString($_SERVER['HTTP_USER_AGENT']),
    );
    if ($req->q['hash'] === '')
    {
     $a = new stdClass;
     $a->{'failure reason'} = 'invalid hash';
     if (isset($_GET['json'])) {echo json_encode($a);}
     else {echo BitTorrentTracker::bencode($a);}
     $req->terminate();
    }
    if ($req->q['uk'] !== '')
    {
     $update = array(
       '$set' => array(
          'uk' => $req->q['uk'],
          'atime' => time(),
          'updated' => 1,
      )
     );
     if ($req->q['event'] === 'stopped')
     {
      $update['$inc'] = array(
       'downloaded' => $req->q['downloaded'],
       'uploaded' => $req->q['uploaded']
      );
     }
     $req->appInstance->db->btusers->update(
      array('uk' => $req->q['uk']),
      $update
     );
    }
    $req->appInstance->db->btstat->upsert(array(
      'hash' => $req->q['hash'],
      'uk' => array('$in' => array('',$req->q['uk'])),
      'peer' => new MongoBinData($req->q['peer']),
      'current' => 1,
    ),array(
       'hash' => $req->q['hash'],
       'uk' => $req->q['uk'],
       'peer' => new MongoBinData($req->q['peer']),
       'uploaded' => (float) $req->q['uploaded'],
       'downloaded' => (float) $req->q['downloaded'],
       'current' => 1,
       'ltime' => time(),
    ));
    if ($req->q['event'] === 'stopped')
    {
     $req->appInstance->db->btstat->updateMulti(array(
      'hash' => $req->q['hash'],
      'uk' => array('$in' => array('',$req->q['uk'])),
      'peer' => new MongoBinData($req->q['peer']),
     ),array(
      '$set' => array('current' => 0)
     ));
    }     
    if ($req->q['event'] === 'completed')
    {
     $req->appInstance->db->btorrents->upsert(
      array('hash' => $req->q['hash']),
      array(
       '$set' => array('hash' => $req->q['hash']),
       '$inc' => array('downloaded' => 1)
     ));
    }
    if ($req->q['event'] === 'stopped')
    {
     $req->appInstance->db->btswarm->remove(array(
       'hash' => $req->q['hash'],
       'peer' => new MongoBinData($req->q['peer']),
       'key' => array('$in' => array(new MongoBinData(''),new MongoBinData($req->q['key']))),
       'uk' => array('$in' => array('',$req->q['uk'])),
     ));
    }
    else
    {
     $req->appInstance->db->btswarm->upsert(
      array(
       'hash' => $req->q['hash'],
       'peer' => new MongoBinData($req->q['peer']),
       'key' => array('$in' => array(new MongoBinData(''),new MongoBinData($req->q['key']))),
       'uk' => array('$in' => array('',$req->q['uk'])),
     )
     ,array(
        'hash' => $req->q['hash'],
        'peer' => new MongoBinData($req->q['peer']),
        'key' => new MongoBinData($req->q['key']),
        'uk' => $req->q['uk'],
        'ip' => $req->q['ip'],
        'port' => (int) $req->q['port'],
        'uploaded' => (float) $req->q['uploaded'],
        'downloaded' => (float) $req->q['downloaded'],
        'left' => (float) $req->q['left'],
        'ltime' => time(),
        'client' => $req->q['client'],
     ));
    }
    $req->eState = 'inprogress';
    $req->countLeeches($req->q['hash']);
    $req->countSeeds($req->q['hash']);
    $req->findPeers();
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    $a = new stdClass;
    $a->interval = $req->reqInterval;
    $a->{'min interval'} = $req->reqMinInterval;
    $a->{'tracker id'} = $req->trackerId;
    $a->complete = $req->completeNum[$req->q['hash']];
    $a->incomplete = $req->incompleteNum[$req->q['hash']];
    $a->peers = new BitTorrentTracker_bencode_mutable($req->peers);
    if (isset($_GET['json'])) {echo json_encode($a);}
    else {echo BitTorrentTracker::bencode($a);}
    return Request::DONE;
   }
  }
  elseif ($req->action === 'scrape')
  {
   if ($req->eState === '')
   {
    if (isset($_GET['hash_id'])) {$req->scrapeHashes = array(self::getString($_GET['hash_id']));}
    else
    {
     parse_str(strtr($_SERVER['QUERY_STRING'],array(
      '&?' => '&',
      'info_hash=' => 'info_hash[]=',
     )),$params);
     if (isset($params['info_hash'])) {$req->scrapeHashes = $params['info_hash'];}
     else {$req->scrapeHashes = array();}
    }
    foreach ($req->scrapeHashes as $hash)
    {
     $req->countLeeches($hash);
     $req->countSeeds($hash);
     $req->getTorrentByHash($hash);
    }
    $req->sleep($req->timeout);
   }
   elseif ($req->eState === 'done')
   {
    $a = new stdClass;
    $a->files = new stdClass;
    foreach ($req->scrapeHashes as $hash)
    {
     $f = new stdClass;
     $f->complete = $req->completeNum[$hash];
     $f->incomplete = $req->incompleteNum[$hash];
     $f->downloaded = isset($req->fileInfo[$hash]['downloads'])?$req->fileInfo[$hash]['downloads']:NULL;
     if (isset($req->fileInfo[$hash]['name'])) {$f->name = $req->fileInfo[$hash]['name'];}
     $a->files->{$hash} = $f;
    }
    $a->flags = new stdClass;
    $a->flags->interval = $req->reqInterval;
    $a->flags->{'min interval'} = $req->reqMinInterval;
    $a->flags->{'tracker id'} = $req->trackerId;
    if (isset($_GET['json'])) {echo json_encode($a);}
    else {echo BitTorrentTracker::bencode($a);}
    return Request::DONE;
   }
  }
  else
  {
   $req->header('404 Not Found');
   echo json_encode(array(
    'error' => 404,
    'errmsg' => 'Undefined action \''.$req->action.'\'.',
   ));
   return Request::DONE;
  }
 }
}
class BitTorrentTracker_TorrentImporter extends Request
{
 public $concurrencyLimit = 5;
 public $dir;
 public $handle;
 public function init()
 {
  $this->dir = '/home/web/jailed/bt.loopback.su/cli/kinozal.tv/';
  if (!file_exists($this->dir) || (!$this->handle = opendir($this->dir)))
  {
   $this->finish();
  }
 }
 public function run()
 {
  return Request::DONE;
  $req = $this;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  while (TRUE)
  {
   if (sizeof($this->appInstance->filestreams) > $this->concurrencyLimit)
   {
    Daemon::log(__METHOD__.': Sleep.');
    $req->sleep();
   }
   if (($f = readdir($this->handle)) === FALSE)
   {
    Daemon::log(__METHOD__.': Finish.');
    return Request::DONE;
   }
   if (preg_match('~^(\d+)\.torrent$~',$f,$m))
   {
    $this->appInstance->addTorrentFromFile($this->dir.$f,array('publisher-url' => 'http://kinozal.tv/details.php?id='.$m[1]));
   }
  }
  return Request::DONE;
 }
}

class BitTorrentTracker_RatingUpdate extends Request
{
 public $processing = 0;
 public $concurrencyLimit = 1;
 public function run()
 {
  $req = $this;
  $appInstance = $this->appInstance;
  $reqID = $this->idAppQueue;
  $req->appInstance->LockClient->job(__CLASS__,FALSE,function($jobname,$command,$client) use ($appInstance, $reqID)
  {
   if (!isset($appInstance->queue[$reqID])) {return;}
   $req = $appInstance->queue[$reqID];
   $req->appInstance->db->btstat->group(function($cursor) {},array(
    'cond' => array('current' => 1),
    'key' => array('uk' => true),
    'initial' => array('uploaded' => 0,'downloaded' => 0),
    'reduce' => 'function (doc,out) {out.downloaded += doc.downloaded; out.uploaded += doc.uploaded;}',
    'finalize' => 'function (o) {db.btusers.update({uk: o.uk},{"$set": {"uploadedCurrent": o.uploaded, "downloadedCurrent": o.downloaded, "updated": 1}});}',
   ));
   $req->appInstance->db->evaluate('db.btusers.find({updated: 1}).sort({utime: 1}).forEach(function (o)
    {
     if (typeof o.uploaded == "undefined") {o.uploaded = 0;}
     if (typeof o.uploadedCurrent == "undefined") {o.uploadedCurrent = 0;}
     if (typeof o.downloaded == "undefined") {o.downloaded  = 0;}
     if (typeof o.downloadedCurrent == "undefined") {o.downloadedCurrent = 0;}
     db.btusers.update({uk: o.uk},{"$set": {
         "rating": Math.floor((o.uploaded+o.uploadedCurrent)/(o.downloaded+o.downloadedCurrent)*100)/100,
         "updated": 0,
         "utime":  Math.floor(new Date().getTime()/1000)
     }});
    });
   ',function($result) use ($client, $jobname)
   {
    $client->done($jobname);
   });
  });
  $req->sleep(60);
 }
}
class BitTorrentTracker_bencode_mutable
{
 public $value;
 public function __construct($value) {$this->value = $value;}
 public function __toString() {return $this->value;}
}
class BDecode
{
 public static function fromString($str)
 {
 	$decoder = new BDecode;
	$return = $decoder->decodeEntry($str);
	return $return[0];
 }
 public function numberdecode($wholefile, $start)
 {
	$ret[0] = 0;
	$offset = $start;

	// Funky handling of negative numbers and zero
	$negative = false;
	if ($wholefile[$offset] == '-')
	{
		$negative = true;
		$offset++;
	}
	if ($wholefile[$offset] == '0')
	{
		$offset++;
		if ($negative)
			return array(false);
		if ($wholefile[$offset] == ':' || $wholefile[$offset] == 'e')
		{
			$offset++;
			$ret[0] = 0;
			$ret[1] = $offset;
			return $ret;
		}
		return array(false);
	}
	while (true)
	{

		if ($wholefile[$offset] >= '0' && $wholefile[$offset] <= '9')
		{
			
			$ret[0] *= 10;
			$ret[0] += ord($wholefile[$offset]) - ord("0");
			$offset++;
		}
		// Tolerate : or e because this is a multiuse function
		else if ($wholefile[$offset] == 'e' || $wholefile[$offset] == ':')
		{
			$ret[1] = $offset+1;
			if ($negative)
			{
				if ($ret[0] == 0)
					return array(false);
				$ret[0] = - $ret[0];
			}
			return $ret;
		}
		else
			return array(false);
	}
 }
 public function decodeEntry($wholefile, $offset=0)
 {
	if ($wholefile[$offset] == 'd')
		return $this->decodeDict($wholefile, $offset);
	if ($wholefile[$offset] == 'l')
		return $this->decodelist($wholefile, $offset);
	if ($wholefile[$offset] == "i")
	{
		$offset++;
		return $this->numberdecode($wholefile, $offset);
	}
	// String value: decode number, then grab substring
	$info = $this->numberdecode($wholefile, $offset);
	if ($info[0] === false)
		return array(false);
	$ret[0] = substr($wholefile, $info[1], $info[0]);
	$ret[1] = $info[1]+strlen($ret[0]);
	return $ret;
}

function decodeList($wholefile, $start)
{
	$offset = $start+1;
	$i = 0;
	if ($wholefile[$start] != 'l')
		return array(false);
	$ret = array();
	while (true)
	{
		if ($wholefile[$offset] == 'e')
			break;
		$value = $this->decodeEntry($wholefile, $offset);
		if ($value[0] === false)
			return array(false);
		$ret[$i] = $value[0];
		$offset = $value[1];
		$i ++;
	}

	// The empy list is an empty array. Seems fine.
	$final[0] = $ret;
	$final[1] = $offset+1;
	return $final;



}

// Tries to construct an array
function decodeDict($wholefile, $start=0)
{
	$offset = $start;
	if ($wholefile[$offset] == 'l')
		return $this->decodeList($wholefile, $start);
	if ($wholefile[$offset] != 'd')
		return false;
	$ret = array();
	$offset++;
	while (true)
	{	
		if ($wholefile[$offset] == 'e')
		{
			$offset++;
			break;
		}
		$left = $this->decodeEntry($wholefile, $offset);
		if (!$left[0])
			return false;
		$offset = $left[1];
		if ($wholefile[$offset] == 'd')
		{
			// Recurse
			$value = $this->decodedict($wholefile, $offset);
			if (!$value[0])
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset= $value[1];
			continue;
		}
		else if ($wholefile[$offset] == 'l')
		{
			$value = $this->decodeList($wholefile, $offset);
			if (!$value[0] && is_bool($value[0]))
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset = $value[1];
		}
		else
		{
 			$value = $this->decodeEntry($wholefile, $offset);
			if ($value[0] === false)
				return false;
			$ret[addslashes($left[0])] = $value[0];
			$offset = $value[1];
		}
	}
	if (empty($ret))
		$final[0] = true;
	else
		$final[0] = $ret;
	$final[1] = $offset;
   	return $final;


}


} // End of class declaration.