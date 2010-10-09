<?php

/**
 * @package Applications
 * @subpackage ServerStatus
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ServerStatus extends AppInstance {

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ServerStatus_Request($this, $upstream, $req);
	}
	
}

class ServerStatus_Request extends HTTPRequest {

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$stime = microtime(TRUE);
		$this->header('Content-Type: text/html; charset=utf-8');

		?>
 <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"> 
<html xmlns="http://www.w3.org/1999/xhtml"> 
<head> 
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" /> 
<title>Server status.</title> 
</head> 
<body>
<br />Uptime: <b><?php echo Daemon::date_period_text(Daemon::$startTime, time()); ?></b>
<br /><br /><b>State of workers:</b><?php $stat = Daemon::getStateOfWorkers(); ?>
<br />Idle: <?php echo $stat['idle']; ?>
<br />Busy: <?php echo $stat['busy']; ?>
<br />Total alive: <?php echo $stat['alive']; ?>
<br />Shutdown: <?php echo $stat['shutdown']; ?>
<br />Pre-init: <?php echo $stat['preinit']; ?>
<br />Wait-init: <?php echo $stat['waitinit']; ?>
<br />Init: <?php echo $stat['init']; ?>
<br />
<br />Request took: <?php printf('%f', round(microtime(TRUE) - $stime, 6)); ?>
</body>
</html>
<?php
		return;
	}
	
}
