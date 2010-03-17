<?php
return new MongoNode;
class MongoNode extends AppInstance
{
 public $db;
 public $cache;
 public $RTEPClient;
 public $LockClient;
 public $cursor;
 public function init()
 {
  Daemon::$settings += array(
   'mod'.$this->modname.'enable' => 0,
  );
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->LockClient = Daemon::$appResolver->getInstanceByAppName('LockClient');
   Daemon::log(__CLASS__.' up.');
   $this->db = Daemon::$appResolver->getInstanceByAppName('MongoClient');
   $this->cache = Daemon::$appResolver->getInstanceByAppName('MemcacheClient');
   $this->RTEPClient = Daemon::$appResolver->getInstanceByAppName('RTEPClient');
  }
 }
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $appInstance = $this;
   $this->LockClient->job(__CLASS__,TRUE,function($jobname) use ($appInstance)
   {
    $r = new stdClass;
    $r->attrs = new stdClass;
    $r->attrs->stdin_done = TRUE;
    $r->attrs->params_done = TRUE;
    $r->attrs->chunked = FALSE;
    $appInstance->pushRequest(new MongoNode_ReplicationRequest($appInstance,$appInstance,$r));
   });
  }
 }
 public function cacheObject($o)
 {
  if (Daemon::$settings['logevents']) {Daemon::log(get_class($this).'::'.__METHOD__.'('.json_encode($o).')');}
  if (isset($o['_key']))
  {
   $this->cache->set($o['_key'],bson_encode($o));
   $this->cache->set('_id.'.((string)$o['_id']),$o['_key']);
  }
  if (isset($o['_ev']))
  {
   $o['name'] = $o['_ev'];
   if (Daemon::$settings['logevents']) {Daemon::log('MongoNode send event '.$o['name']);}
   $this->RTEPClient->client->request(array(
    'op' => 'event',
    'event' => $o,
   ));
  }
 }
 public function deleteObject($o)
 {
  if (Daemon::$settings['logevents']) {Daemon::log(get_class($this).'::'.__METHOD__.'('.json_encode($o).')');}
  $this->cache->get('_id.'.((string)$o['_id']),function($m) use ($o)
  {
   if (is_string($m->result))
   {
    $m->appInstance->delete($m->result);
    $m->appInstance->delete('_id.'.$o['_id']);
   }
  });
 }
 public function initSlave($point)
 {
  $node = $this;
  $this->db->{'local.oplog.$main'}->find(function($cursor) use ($node)
  {
   $node->cursor = $cursor;
   $cursor->state = 1;
   $cursor->lastOpId = NULL;
   foreach ($cursor->items as $k => &$item)
   {
    if (Daemon::$settings['logevents']) {Daemon::log(get_class($node).': caught oplog-record with ts = ('.Daemon::var_dump($item['ts']).')');}
    $cursor->lastOpId = $item['ts'];
    if ($item['op'] == 'i') {$node->cacheObject($item['o']);}
    elseif ($item['op'] == 'd') {$node->deleteObject($item['o']);}
    elseif ($item['op'] == 'u')
    {
     if (isset($item['b']) && ($item['b'] === FALSE))
     {
      $item['o']['_id'] = $item['o2']['_id'];
      $node->cacheObject($item['o']);
     }
     else
     {
      $cursor->appInstance->{$item['ns']}->findOne(function($item) use ($node)
      {
       $node->cacheObject($item);
      },array('where' => array('_id' => $item['o2']['_id'])));
     }
    }
    unset($cursor->items[$k]);
   }
  },array(
   'tailable' => TRUE,
   'sort' => array('$natural' => 1),
   'snapshot' => 1,
   //'where' => array('ts' => array('$gt' => $point),'$where' => '(typeof(this._key) == \'string\') || (typeof(this._ev) == \'string\')'),
   'where' => array('ts' => array('$gt' => $point),'$exists' => array('_key' => TRUE)),
   'parse_oplog' => TRUE,
  ));
 }
}
class MongoNode_ReplicationRequest extends Request
{
 public $inited = FALSE;
 public function run()
 {
  if (!$this->appInstance->cursor)
  {
   if (!$this->inited)
   {
    $req = $this;
    $this->inited = TRUE;
    $this->appInstance->cache->get('_rp',function ($answer) use ($req)
    {
     $req->inited = FALSE;
     $e = explode(' ',$answer->result);
     if (isset($e[1]))
     {
      $ts = new MongoTimestamp((int) $e[0], (int) $e[1]);
     }
     else
     {
      $ts = new MongoTimestamp(0, 0);
     }
     if (Daemon::$settings['logevents']) {Daemon::log('MongoNode: replication point - '.$answer->result.' ('.Daemon::var_dump($ts).')');}
     $req->appInstance->initSlave($ts);
    });
   }
  }
  elseif (!$this->appInstance->cursor->session->busy)
  {
   if ($this->appInstance->cursor->lastOpId !== NULL)
   {
    $this->appInstance->cache->set('_rp',$this->appInstance->cursor->lastOpId);
    $this->appInstance->cursor->lastOpId = NULL;
   }
   try {$this->appInstance->cursor->getMore();}
   catch (MongoClientSessionFinished $e)
   {
    $this->appInstance->cursor = FALSE;
   }
  }
  $this->sleep(0.3);
 }
}
