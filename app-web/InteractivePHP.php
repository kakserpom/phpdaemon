<?php
class InteractivePHP extends AppInstance {

	public $db;
	public $proc = array();

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->db = Daemon::$appResolver->getInstanceByAppName('MongoClient');
	}

	/**
	 * Returns pointer of process.
	 * @param string Id.
	 * @return resource Pointer.
	 */
	public function getSession($id) {
		if (!isset($this->proc[$id])) {
			return FALSE;
		}

		return $this->proc[$id];
	}

	/**
	 * Sends command to the process.
	 * @param string Id.
	 * @param string Commmand.
	 * @return resource Pointer.
	 */
	public function sendCommand($id, $cmd) {
		if (!isset($this->proc[$id])) {
			$this->proc[$id] = new AsyncProcess;
			$this->proc[$id]->result = '';

			$this->proc[$id]->onReadData(
				function($stream, $data) {
					$stream->result .= $data;
				}
			);

			$this->proc[$id]->nice(256);
			$this->proc[$id]->execute('php -a');
		}

		$this->proc[$id]->eof();

		if ($cmd !== '') {
			$this->proc[$id]->write($cmd . "\n");
		}
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new InteractivePHPRequest($this, $upstream, $req);
	}
}

class InteractivePHPRequest extends HTTPRequest {

	public $eState = 0;

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->eState === 1) {
			goto sleep;
		}

		if ($this->eState === 2) {
			goto result;
		}

		$this->header('Content-Type: text/html; charset=utf-8');

		$this->session = self::getString($_REQUEST['session']);

		if (self::getString($_REQUEST['pwd']) != 'abcd') {
			echo 'Permission denied.';
			return;
		}

		if (!strlen($this->session)) {
			$this->session = md5(microtime() . "\t" . $_SERVER['REMOTE_ADDR'] . "\t" . mt_rand(0, mt_getrandmax()));
			$this->header('Location: ' . $_SERVER['DOCUMENT_URI'] . '?session=' . urlencode($this->session));

			Daemon::log('redirect to ' . $_SERVER['DOCUMENT_URI'] . '?session=' . urlencode($this->session));

			echo 'Redirect';

			ob_flush();

			return;
		}

		Daemon::log('start sendcommand');

		$this->appInstance->sendCommand($this->session, self::getString($_REQUEST['command']));

		Daemon::log('end sendcommand ' . $_REQUEST['command']);

		sleep:
			++$this->eState;
			$this->sleep();

		result:
			?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Iteractive PHP shell</title>
</head>
<body>
<br />
<form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES); ?>" method="post">
<fieldset><pre>
<?php

echo htmlspecialchars($this->appInstance->getSession($this->session)->result);

?>
</pre></fieldset><br />
<?php

if (!$this->appInstance->getSession($this->session)->EOF) {
	?>
<textarea name="command" cols="80" rows="5"></textarea>
<br /><input type="submit" value="Send" />
<?php

} else {
	echo '<br />Session ended.';
}

?>
<br /><a href="<?php echo htmlspecialchars($_SERVER['DOCUMENT_URI'], ENT_QUOTES); ?>">Create new session.</a>
</form>
</body>
</html><?php
		return;
	}
	
}
