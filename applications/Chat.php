<?php
/*
DRAFT:

 db.chatsessions.ensureIndex({id:1},{unique: true});
*/
return new Chat;
class Chat extends AppInstance
{
 public $sessions = array();
 public $dbname;
 public $db;
 public $tags;
 public $minMsgInterval;
 public function init()
 {
  Daemon::$settings += array(
   'mod'.$this->modname.'dbname' => 'chat',
   'mod'.$this->modname.'adminpassword' => '',
   'mod'.$this->modname.'enable' => 0
  );
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   Daemon::log(__CLASS__.' up.');
   $this->db = Daemon::$appResolver->getInstanceByAppName('MongoClient');
   $this->dbname = &Daemon::$settings[$k = 'mod'.$this->modname.'dbname'];
   $this->tags = array();
   $this->minMsgInterval = 1;
  }
 }
 public function getTag($name)
 {
  if (isset($this->tags[$name])) {return $this->tags[$name];}
  return $this->tags[$name] = new ChatTag($name,$this);
 }
 public function kickUsers($users,$tags = '',$reason = '')
 {
  $users = trim($users);
  if ($users === '') {return FALSE;}
  $tags = trim($tags);
  $this->broadcastEvent(array(
   'type' => 'kickUsers',
   'users' => explode(',',$users),
   'tags' => ($tags !== ''?explode(',',$tags):array('%all')),
   'reason' => $reason,
  ));
  return TRUE;
 }
 public function compareMask($username,$masks = array())
 {
  foreach ($masks as $mask)
  {
   if (fnmatch($mask,$username,FNM_CASEFOLD)) {return TRUE;}
  }
  return FALSE;
 }
 public function forceChangeNick($name,$newname)
 {
  $name = trim($name);
  if ($name === '') {return FALSE;}
  $newname = trim($newname);
  if ($newname === '') {return FALSE;}
  $this->broadcastEvent(array(
   'type' => 'forceChangeNick',
   'username' => $name,
   'changeto' => $newname,
   'tags' => '%all',
  ));
  return TRUE;
 }
 public function validateUsername($s) {return preg_match('~^(?!@)[A-Za-z\-_!0-9\.\wА-Яа-я]+$~u',$s);}
 public function broadcastEvent($doc)
 {
  if (!isset($doc['ts'])) {$doc['ts'] = microtime(TRUE);}
  if (!isset($doc['tags'])) {$doc['tags'] = array();}
  $this->db->{$this->dbname.'.chatevents'}->insert($doc);
 }
 public function onHandshake($client) {return $this->sessions[$client->connId] = new ChatSession($client,$this);}
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
   if ($this->WS)
   {
    $this->WS->routes['Chat'] = array($this,'onHandshake');
   }
   $appInstance = $this;
   $r = new stdClass;
   $r->attrs = new stdClass;
   $r->attrs->stdin_done = TRUE;
   $r->attrs->params_done = TRUE;
   $r->attrs->chunked = FALSE;
   $appInstance->pushRequest(new Chat_MsgQueueRequest($appInstance,$appInstance,$r));
  }
 }
}
class ChatAntifloodPlugin
{
 public function onMessage()
 {
 }
}
class ChatTag
{
 public $appInstance;
 public $sessions = array();
 public $tag;
 public $cursor;
 public $counter = 0;
 public function __construct($tag,$appInstance)
 {
  $this->tag = $tag;
  $this->appInstance = $appInstance;
 }
 public function touch()
 {
  if (!$this->cursor)
  {
   $tag = $this;
   $this->appInstance->db->{$this->appInstance->dbname.'.chatevents'}->find(function($cursor) use ($tag)
   {
    $tag->cursor = $cursor;
    foreach ($cursor->items as $k => &$item)
    {
     if ($item['type'] === 'kickUsers')
     {
      foreach ($tag->sessions as $id => $v)
      {
       $sess = $tag->appInstance->sessions[$id];
       if (($sess->username !== NULL) && ($tag->appInstance->compareMask($sess->username,$item['users'])))
       {
        $sess->removeTags(array($tag->tag),TRUE);
        $sess->sysMsg('* You were kicked from #'.$tag->tag.'.'.($item['reason'] !== ''?' Reason: '.$item['reason']:''));
        $tag->appInstance->broadcastEvent(array(
         'type' => 'msg',
         'mtype' => 'system',
         'text' => ' * Kicked: '.$sess->username.($item['reason'] !== ''?', reason: '.$item['reason']:''),
         'color' => 'green',
         'tags' => $tag->tag,
        )); 
       }
      }
     }
     elseif ($item['type'] === 'forceChangeNick')
     {
      foreach ($tag->sessions as $id => $v)
      {
       $sess = $tag->appInstance->sessions[$id];
       if (($sess->username !== NULL) && ($sess->username === $item['username']))
       {
        $sess->setUsername($item['changeto'],TRUE);
       }
      }
     }
     else
     {
      $item['_id'] = (string) $item['_id'];
      if (isset($item['sid'])) {$item['sid'] = (string) $item['sid'];}
      $packet = "\x00".ChatSession::serialize($item)."\xFF";
      foreach ($tag->sessions as $id => $v)
      {
       $s = $tag->appInstance->sessions[$id];
       if ($s->putMsgId($item['_id'])) {$s->client->write($packet);}
      }
     }
     unset($cursor->items[$k]);
    }
    //if ($cursor->finished) {$cursor->destroy();}
   },array(
    'tailable' => TRUE,
    'sort' => array('ts' => 1),
    'where' => array('ts' => array('$gt' => microtime(TRUE)),'tags' => array('$in' => array($this->tag,'%all')))
   ));
  }
  elseif (!$this->cursor->session->busy)
  {
   try {$this->cursor->getMore();}
   catch (MongoClientSessionFinished $e)
   {
    $this->cursor = FALSE;
   }
  }
 }
}
class ChatSession
{ 
 public $client;
 public $username;
 public $tags = array();
 public $sid;
 public $lastMsgTS;
 public $su = FALSE;
 public $lastMsgIDs = array();
 public $statusmsg;
 public function __construct($client,$appInstance)
 {
  $this->client = $client;
  $this->lastMsgIDs = new SplStack();
  $this->appInstance = $appInstance;
  $this->sid = new MongoId();
  $this->updateSession(array(
    'atime' => microtime(TRUE),
    'ltime' => microtime(TRUE),
  ));
 }
 public function gracefulShutdown()
 {
  return FALSE;
 }
 public function putMsgId($s)
 {
  for ($i = 0, $c = count($this->lastMsgIDs); $i < $c; ++$i)
  {
   if ($this->lastMsgIDs[$i] === $s) {return FALSE;}
  }
  $this->lastMsgIDs[] = $s;
  if ($c >= 4) {$this->lastMsgIDs->shift();}
  return TRUE;
 }
 public function onFinish()
 {
  $this->setTags(array());
  $this->appInstance->db->{$this->appInstance->dbname.'.chatsessions'}->remove(array('id' => $this->sid));
  unset($this->appInstance->sessions[$this->client->connId]);
 }
 public function onAddedTags($tags,$silence = FALSE)
 {
  foreach ($tags as $tag)
  {
   ++$this->appInstance->getTag($tag)->counter;
   $this->appInstance->getTag($tag)->sessions[$this->client->connId] = TRUE;
  }
  if ($this->username !== NULL)
  {
   $this->broadcastEvent(array(
    'type' => 'joinsUser',
    'sid' => (string) $this->sid,
    'username' => $this->username,
    'tags' => $tags,
    'statusmsg' => $this->statusmsg,
   ));
   if (!$silence)
   {
    $this->broadcastEvent(array(
     'type' => 'msg',
     'mtype' => 'system',
     'text' => '* Joins: '.$this->username,
     'color' => 'green',
     'tags' => $tags,
    ));
   }
  }
 }
 public function onRemovedTags($tags,$silence = FALSE)
 {
  foreach ($tags as $tag)
  {
   --$this->appInstance->getTag($tag)->counter;
   unset($this->appInstance->tags[$tag]->sessions[$this->client->connId]);
  }
  if ($this->username !== NULL)
  {
   $this->broadcastEvent(array(
   'type' => 'partsUser',
    'sid' => (string) $this->sid,
    'username' => $this->username,
    'tags' => $tags,
   ));
   if (!$silence)
   {
    $this->broadcastEvent(array(
     'type' => 'msg',
     'mtype' => 'system',
     'text' => '* Parts: '.$this->username,
     'color' => 'green',
     'tags' => $tags,
    ));
   }
  }
 }
 public function addTags($tags)
 {
  $this->setTags(array_unique(array_merge($this->tags,$tags)));
 }
 public function removeTags($tags,$silence = FALSE)
 {
  $this->setTags(array_diff($this->tags,$tags),$silence);
 }
 public function setTags($tags,$silence = FALSE)
 {
  $removetags = array();
  $addtags = array();
  foreach ($this->tags as $tag)
  {
   if (!in_array($tag,$tags)) {$removetags[] = $tag;}
  }
  foreach ($tags as $tag)
  {
   if (!in_array($tag,$this->tags)) {$addtags[] = $tag;}
  }
  if ($this->tags != $tags)
  {
   $this->tags = $tags;
   $this->updateSession(array(
    'atime' => microtime(TRUE),
    'tags' => $this->tags,
   ));
  }
  if (sizeof($addtags)) {$this->onAddedTags($addtags,$silence);}
  if (sizeof($removetags)) {$this->onRemovedTags($removetags,$silence);}
 }
 public function updateSession($a)
 {
  $a['id'] = $this->sid;
  $this->appInstance->db->{$this->appInstance->dbname.'.chatsessions'}->upsert(
   array('id' => $this->sid),
   array('$set' => $a)
  );
  if (isset($a['statusmsg']))
  {
   $this->sendMessage(array(
    'mtype' => 'status',
    'tags' => $this->tags,
    'from' => $this->username,
    'mtype' => 'status',
    'text' => $a['statusmsg'],
    'color' => 'green',
   ));
   $this->statusmsg = $a['statusmsg'];
  }
  if (isset($a['tags']))
  {
   $this->send(array(
   'type' => 'tags',
   'tags' => $a['tags'],
  ));
  }
 }
 public function send($packet)
 {
  return $this->client->sendFrame(ChatSession::serialize($packet));
 }
 public static function serialize($o)
 {
  return urlencode(json_encode($o));
 }
 public function setUsername($name,$silence = FALSE)
 {
  $name = trim($name);
  if ($name === '')
  {
   if (!$silence) {$this->sysMsg('* /nick <name>: insufficient parameters');}
   return 4;
  }
  if (!$this->appInstance->validateUsername($name)) // need optimization
  {
   if (!$silence) {$this->sysMsg('* /nick: errorneus username');}
   return 2;
  }
  if ($this->username === $name) {return 3;}
  $clientId = $this->client->connId;
  $appInstance = $this->appInstance;
  $this->appInstance->db->{$this->appInstance->dbname.'.chatsessions'}->findOne(function($item) use ($clientId, $appInstance, $name, $silence)
  {
   if (!isset($appInstance->sessions[$clientId])) {return;}
   $session = $appInstance->sessions[$clientId];
   if ($item) // we have got the same username
   {
    if (!$silence) {$session->sysMsg('* /nick: the username is taken already');}
    return;
   }
   $session->updateSession(array(
     'atime' => microtime(TRUE),
     'username' => $name,
   ));
   $session->send(array(
     'type' => 'cstatus',
     'username' => $name,
   ));
   if ($session->username !== NULL)
   {
    $session->broadcastEvent(array(
     'type' => 'msg',
     'mtype' => 'astatus',
     'from' => $session->username,
     'text' => 'is now known as '.$name,
     'color' => 'green',
    ));
    $session->broadcastEvent(array(
     'type' => 'changedUsername',
     'sid' => (string) $session->sid,
     'old' => $session->username,
     'new' => $name,
    ));
   }
   else
   {
    $session->broadcastEvent(array(
     'type' => 'joinsUser',
     'sid' => (string) $session->sid,
     'username' => $name,
     'tags' => $session->tags,
     'statusmsg' => $session->statusmsg,
    ));
    $session->broadcastEvent(array(
     'type' => 'msg',
     'mtype' => 'system',
     'text' => '* Joins: '.$name,
     'color' => 'green',
    ));
   }
   $session->username = $name;
  },array('where' => array(
    'username' => $name,
    'atime' => array('$gt' => microtime(TRUE)-20),
  )));
  return 1;
 }
 public function onFrame($data,$type)
 {
  $packet = json_decode($data,TRUE);
  if (!$packet) {return;}
  if (!isset($packet['cmd'])) {return;}
  $cmd = $packet['cmd'];
  if (($cmd === 'setUsername') && isset($packet['username']))
  {
   if ($this->username !== NULL) {return;}
   $this->setUsername($packet['username']);
  }
  elseif ($cmd === 'setTags')
  {
   $this->setTags($packet['tags']);
  }
  elseif ($cmd === 'keepalive')
  {
   $this->updateSession(array(
    'atime' => microtime(TRUE),
   ));
  }
  elseif ($cmd == 'getHistory')
  {
   $session = $this;
   $condts = array('$lt' => microtime(TRUE));
   $lastTS = isset($packet['lastTS'])?(float)$packet['lastTS']:0;
   if ($lastTS > 0) {$condts['$gt'] = $lastTS;}
   $this->appInstance->db->{$this->appInstance->dbname.'.chatevents'}->find(function($cursor) use ($session)
   {
    $tag->cursor = $cursor;
    $cursor->items = array_reverse($cursor->items);
    foreach ($cursor->items as $k => &$item)
    {
     $item['_id'] = (string) $item['_id'];
     if (isset($item['sid'])) {$item['sid'] = (string) $item['sid'];}
     $item['history'] = TRUE;
     $session->send($item);
     unset($cursor->items[$k]);
    }
    $cursor->destroy();
   },array(
    'sort' => array('ts' => -1),
    'where' => array('ts' => $condts,'tags' => array('$in' => $packet['tags'])),
    'limit' => -20,
   ));
  }
  elseif ($cmd == 'getUserlist')
  {
   $session = $this;
   $this->appInstance->db->{$this->appInstance->dbname.'.chatsessions'}->find(function($cursor) use ($session)
   {
    $tag->cursor = $cursor;
    $cursor->items = array_reverse($cursor->items);
    foreach ($cursor->items as $k => &$item)
    {
     unset($item['_id']);
     $item['id'] = isset($item['id'])?(string) $item['id']:'';
    }
    $session->send(array('type' => 'userlist', 'userlist' => $cursor->items));
    $cursor->destroy();
   },array(
    'sort' => array('ctime' => -1),
    'where' => array(
       'tags' => array('$in' => $packet['tags']),
       'atime' => array('$gt' => microtime(TRUE)-20),
       'username' => array('$exists' => TRUE),
    ),
    'limit' => -200,
   ));
  }
  elseif ($cmd === 'sendMessage')
  {
   if (!isset($packet['tags'])) {return FALSE;}
   if (!$this->username) {return FALSE;}
   $username = $this->username;
   if ((!isset($packet['text'])) || (trim($packet['text']) === '')) {return FALSE;}
   $text = $packet['text'];
   $color = isset($packet['color'])?(string) $packet['color']:'';
   static $colors = array('black','red','green','blue');
   if (!in_array($color,$colors)) {$color = 'black';}
   $c = substr($text,0,1);
   if ($c === '/')
   {
    $e = explode(' ',$text,2);
    $m = strtolower(substr($e[0],1));
    $text = isset($e[1])?trim($e[1]):'';
    if ($m === 'me')
    {
     if ($text === '') {$this->sysMsg('* /me <message>: insufficient parameters');}
     else
     {
      $this->updateSession(array('statusmsg' => $text));
     }
    }
    elseif ($m === 'tags')
    {
     $tags = trim($text);
     if ($tags !== '') {$this->setTags(array_map('trim',explode(',',$tags)));}
     $this->sysMsg('* /tags: '.implode(', ',$this->tags));
    }
    elseif ($m === 'join')
    {
     $tags = $text;
     if ($tags !== '') {$this->addTags(array_map('trim',explode(',',$tags)));}
     else {$this->sysMsg('* /join <tag1>{,<tagN>}: insufficient parameters');}
    }
    elseif ($m === 'part')
    {
     $tags = $text;
     if ($tags !== '') {$this->removeTags(array_map('trim',explode(',',$tags)));}
     else {$this->sysMsg('* /part <tag1>{,<tagN>}: insufficient parameters');}
    }
    elseif ($m === 'nick')
    {
     $this->setUsername($text);
    }
    elseif ($m === 'thetime')
    {
     $this->sysMsg('* Current time: '.date('r'));
    }
    elseif ($m === 'su')
    {
     $password = $text;
     if ($this->su || (($password !== '') && ($password === Daemon::$settings[$k = 'mod'.$this->appInstance->modname.'adminpassword'])))
     {
      $this->su = TRUE;
      $this->sysMsg('* You\'ve got the power.');
     }
     else
     {
      $this->sysMsg('* Your powers are weak, old man.');
     }
    }
    elseif ($m === 'kick')
    {
     $e = explode(' ',$text,3);
     $users = isset($e[0])?trim($e[0]):'';
     $tags = isset($e[1])?trim($e[1]):'';
     $reason = isset($e[2])?trim($e[2]):'';
     if ($users === '') {$this->sysMsg('* /kick <name> [<tags>] [<reason>]: insufficient parameters');}
     else
     {
      if (!$this->su) {$this->sysMsg('* Your powers are weak, old man.');}
      else {$this->appInstance->kickUsers($users,$tags,$reason);}
     }
    }
    elseif ($m === 'fchname')
    {
     $e = explode(' ',$text);
     $name = isset($e[0])?trim($e[0]):'';
     $newname = isset($e[1])?trim($e[1]):'';
     if (($name === '') || ($newname === '')) {$this->sysMsg('* /fchname <name> <newname>: insufficient parameters');}
     elseif (!$this->appInstance->validateUsername($newname)) {$this->sysMsg('* /fchname: newname>');}
     {
      if (!$this->su) {$this->sysMsg('* Your powers are weak, old man.');}
      else {$this->appInstance->forceChangeNick($name,$newname);}
     }
    }
    else
    {
     $this->sysMsg('* '.$m.' Unknown command');
    }
   }
   else
   {
    $doc = array(
     'mtype' => 'pub',
     'tags' => $packet['tags'],
     'from' => $username,
     'text' => $text,
     'color' => $color,
    );
    if (preg_match_all('~(?<=^|\s)@([A-Za-z\-_!0-9\.\wА-Яа-я]+)~u',$text,$m)) {$doc['to'] = $m[1];}
    $this->sendMessage($doc);
   }
   $this->send(array('type' => 'cmdReply','cmd' => $cmd));
  }
 }
 public function sendMessage($doc)
 {
  $doc['type'] = 'msg';
  $t = microtime(TRUE);
  if ($this->lastMsgTS !== NULL)
  {
   $d = $t-$this->lastMsgTS;
   if ($d < $this->appInstance->minMsgInterval)
   {
    $this->sysMsg('* Too fast. Min. interval is '.$this->appInstance->minMsgInterval.' sec. You made '.round($d,4).'.');
    return;
   }
  }
  $this->lastMsgTS = $t; 
  $this->broadcastEvent($doc);
 }
 public function broadcastEvent($doc)
 {
  if (!isset($doc['ts'])) {$doc['ts'] = microtime(TRUE);}
  if (!isset($doc['tags'])) {$doc['tags'] = $this->tags;}
  $doc['sid'] = $this->sid;
  $this->appInstance->db->{$this->appInstance->dbname.'.chatevents'}->insert($doc);
 }
 public function sysMsg($msg)
 {
  $this->send(array(
   'type' => 'msg',
   'mtype' => 'system',
   'text' => $msg,
   'color' => 'green',
   'ts' => microtime(TRUE),
  ));
 }
}
class Chat_MsgQueueRequest extends Request
{
 public $inited = FALSE;
 public function run()
 {
  foreach ($this->appInstance->tags as $tag) {$tag->touch();}
  $this->sleep(0.3);
 }
}
