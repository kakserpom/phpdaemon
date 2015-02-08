<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;

class ExampleRequest extends Generic
{
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run()
	{
		$this->sessionStart();
		try {
			$this->header('Content-Type: text/html');
			$this->setcookie('testcookie', '1');
		} catch (\PHPDaemon\Request\RequestHeadersAlreadySent $e) {}

		$this->registerShutdownFunction(function() {

?>
</html>
<?php

		});

		if (!isset($_SESSION['counter'])) {
			$_SESSION['counter'] = 0;
		}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>It works!</title>
</head>
<body>
<h1>It works! Be happy! ;-) </h1>
*Hello world!<br/>
Testing Error Message: <?php trigger_error('_text_of_notice_'); ?>
<br/>Counter of requests to this Application Instance: <b><?php echo ++$this->appInstance->counter; ?></b>
<br />Counter in session: <?php echo ++$_SESSION['counter']; ?>
<br/>Memory usage: <?php $mem = memory_get_usage();
echo($mem / 1024 / 1024); ?> MB. (<?php echo $mem; ?>)
<br/>Memory real usage: <?php $mem = memory_get_usage(TRUE);
echo($mem / 1024 / 1024); ?> MB. (<?php echo $mem; ?>)
<br/>My PID: <?php echo getmypid(); ?>.
<?php

		$user = posix_getpwuid(posix_getuid());
		$group = posix_getgrgid(posix_getgid());

?>
<br/>My user/group: <?php echo $user['name'] . '/' . $group['name']; ?>
<?php

		$displaystate = TRUE;

		if ($displaystate)
		{

?><br/><br/><b>State of workers:</b><?php $stat = \PHPDaemon\Core\Daemon::getStateOfWorkers(); ?>
<br/>Idle: <?php echo $stat['idle']; ?>
<br/>Busy: <?php echo $stat['busy']; ?>
<br/>Total alive: <?php echo $stat['alive']; ?>
<br/>Shutdown: <?php echo $stat['shutdown']; ?>
<br/>Pre-init: <?php echo $stat['preinit']; ?>
<br/>Init: <?php echo $stat['init']; ?>
<br/>
<?php

		}

?>
<br/><br/>
<br/><br/>

<form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>" method="post" enctype="multipart/form-data">
	<input type="file" name="myfile"/>
	<input type="submit" name="submit" value="Upload"/>
</form>
<br/>

<form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>" method="post">
	<input type="text" name="mytext" value=""/>
	<input type="submit" name="submit" value="Send"/>
</form>
<pre>
<?php

		var_dump([
			'_GET'     => $_GET,
			'_POST'    => $_POST,
			'_COOKIE'  => $_COOKIE,
			'_REQUEST' => $_REQUEST,
			'_FILES'   => $_FILES,
			'_SERVER'  => $_SERVER
		 ]);

?></pre>
<br/>Request took: <?php printf('%f', round(microtime(TRUE) - $_SERVER['REQUEST_TIME_FLOAT'], 6));
//echo '<!-- '. str_repeat('x',1024*1024).' --->';
//echo '<!-- '. str_repeat('x',1024*1024).' --->';
//echo '<!-- '. str_repeat('x',1024*1024).' --->';
?>
</body>
<?php

	}

	public function __destruct() {
		Daemon::log('destructed example request');
	}
}
