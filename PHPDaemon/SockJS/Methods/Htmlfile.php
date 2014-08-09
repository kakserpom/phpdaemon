<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Htmlfile extends Generic {
	use \PHPDaemon\SockJS\Traits\GC;

	protected $contentType = 'text/html';
	protected $callbackParamEnabled = true;
	protected $poll = true;
	public function sendFrame($frame) {
		$this->out("<script>\np(" . htmlspecialchars(json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)). ");\n</script>\r\n");
	}
	public function init() {
		parent::init();
		if ($this->isFinished()) {
			return;
		}
		?><!doctype html>
<html><head>
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
</head><body><h2>Don't panic!</h2>
<script>
document.domain = document.domain;
var c = parent.<?php echo $_GET['c']; ?>;
c.start();
function p(d) {c.message(d);};
window.onload = function() {c.stop();};
</script>
<?php
	}
}
