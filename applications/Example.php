<?php
return new Example;
class Example extends AppInstance
{
 public $counter = 0;
 public $sharedcounter = 1;
 public $RTEPClient;
 public function init()
 {
  $o = $this;
  $this->RTEPClient = Daemon::$appResolver->getInstanceByAppName('RTEPClient');
  if ($this->RTEPClient && $this->RTEPClient->client)
  {
   $this->RTEPClient->client->addEventCallback('visitorHit',function($event) use ($o)
   {
    if (Daemon::$settings['logevents']) {Daemon::log('Caught event '.$event['name'].'.');}
     ++$o->sharedcounter;
   });
  }
 }
 public function onReady()
 {
  // Initialization.
 }
 public function onShutdown()
 {
  // Finalization.
  return TRUE;
 }
 public function beginRequest($req,$upstream) {return new ExampleRequest($this,$upstream,$req);}
}
class ExampleRequest extends Request
{
 public function __destruct()
 {
  //Daemon::log(get_class($this).' destructed');
 }
 public function run()
 {
  $stime = microtime(TRUE);
  $this->header('Content-Type: text/html; charset=utf-8');
  $this->registerShutdownFunction(function()
  {
   ?></html><?php
  });
  if ($this->appInstance->RTEPClient && $this->appInstance->RTEPClient->client)
  {
   $this->appInstance->RTEPClient->client->request(array(
    'op' => 'event',
    'event' => array(
      'name' => 'visitorHit',
      'somevar' => 'somevalue... ',
   )));
  }
 ?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>It works!</title>
</head>
<body>
<h1>It works! Be happy! ;-)</h1>
Hello world!
<br />Counter of requests to this Application Instance: <b><?php echo ++$this->appInstance->counter; ?></b>
<br />Shared counter of requests to this page: <b><?php echo $this->appInstance->sharedcounter; ?></b>
<br />Memory usage: <?php $mem = memory_get_usage(); echo ($mem/1024/1024); ?> MB. (<?php echo $mem; ?>)
<br />Memory real usage: <?php $mem = memory_get_usage(TRUE); echo ($mem/1024/1024); ?> MB. (<?php echo $mem; ?>)
<br />Pool size: <?php echo sizeof(Daemon::$worker->pool); ?>.
<br />My PID: <?php echo getmypid(); ?>.
<?php
$user = posix_getpwuid(posix_getuid());
$group = posix_getgrgid(posix_getgid());
?><br />My user/group: <?php echo $user['name'].'/'.$group['name']; ?>
<?php
$displaystate = TRUE;
if ($displaystate)
{
 ?><br /><br /><b>State of workers:</b><?php $stat = Daemon::getStateOfWorkers(); ?>
<br />Idle: <?php echo $stat['idle']; ?>
<br />Busy: <?php echo $stat['busy']; ?>
<br />Total alive: <?php echo $stat['alive']; ?>
<br />Shutdown: <?php echo $stat['shutdown']; ?>
<br />Pre-init: <?php echo $stat['preinit']; ?>
<br />Wait-init: <?php echo $stat['waitinit']; ?>
<br />Init: <?php echo $stat['init']; ?>
<br />
<?php
}
?>
<br /><br />
<br /><br /><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'],ENT_QUOTES); ?>" method="post" enctype="multipart/form-data">
<input type="file" name="myfile" />
<input type="submit" name="submit" value="Upload" />
</form>
<br />
<form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'],ENT_QUOTES); ?>" method="post">
<input type="text" name="mytext" value="" />
<input type="submit" name="submit" value="Send" />
</form>
<pre>
<?php var_dump(array(
  '_GET' => $_GET,
  '_POST' => $_POST,
  '_COOKIE' => $_COOKIE,
  '_FILES' => $_FILES,
  '_SERVER' => $_SERVER,
 ));
?></pre>
<br />Request took: <?php printf('%f',round(microtime(TRUE)-$stime,6)); ?>
</body><?php
  return 1;
 }
}
