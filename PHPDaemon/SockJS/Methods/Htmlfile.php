<?php
namespace PHPDaemon\SockJS\Methods;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Utils\Crypt;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Htmlfile extends Generic {
	protected $gcEnabled = true;
	protected $contentType = 'text/html';
	protected $callbackParamEnabled = true;
	protected $poll = true;
	protected $pollMode = ['stream'];

	/**
	 * Send frame
	 * @param  string $frame
	 * @return void
	 */
	public function sendFrame($frame) {
		$this->outputFrame("<script>\np(" . htmlspecialchars(json_encode($frame, JSON_UNESCAPED_SLASHES), ENT_NOQUOTES | ENT_HTML401). ");\n</script>\r\n");
		parent::sendFrame($frame);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		parent::init();
		if ($this->isFinished()) {
			return;
		}
		echo str_repeat(' ', 1024);
		echo "\n\n";
		?><!DOCTYPE html>
<html>
<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
</head>
<body>
<h2>Don't panic!</h2>
<script>
	document.domain = document.domain;
	var c = parent.<?php echo $_GET['c']; ?>;
	c.start();
	function p(d) {c.message(d);};
	window.onload = function() {c.stop();};
</script>
</body>
</html>
<?php
	}
}
