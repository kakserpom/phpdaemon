<?php
namespace PHPDaemon\SockJS\Examples;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;

class SimpleRequest extends Generic {
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('Content-Type: text/html');
	?>
<!DOCTYPE html>
<html>
<head>
	<title>SockJS test page</title>
	<script src="//cdn.jsdelivr.net/sockjs/0.3.4/sockjs.min.js"></script>
</head>
<body>
	<script type="text/javascript">
		var sock, logElem;

		function addLog(msg) {
			logElem.innerHTML = msg + '<br />' + logElem.innerHTML;
		}

		function create() {
			logElem = document.getElementById('log');
			sock = new SockJS('http://'+document.domain+':8068/sockjs/');
			sock.onopen = function () {
				addLog('SockJS opened');
			}
			sock.onmessage = function (e) {
				addLog('<<< : ' + e.data);
			}
			sock.onclose = function () {
				addLog('closed');
			}
		}

		function send(data) {
			sock && sock.send(data);
			addLog('>>> : ' + data);
		}
	</script>

	<button onclick="create();">Create SockJS</button>
	<button onclick="send('ping');">Send ping</button>
	<button onclick="sock && sock.close();">Close SockJS</button>
	<div id="log" style="width:300px; height: 300px; border: 1px solid #999999; overflow:auto;"></div>
</body>
</html>

<?php
	}
}
