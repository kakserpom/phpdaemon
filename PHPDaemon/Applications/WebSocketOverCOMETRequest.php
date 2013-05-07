<?php
namespace PHPDaemon\Applications;

use PHPDaemon\HTTPRequest\Generic;

/**
 * @package    Applications
 * @subpackage WebSocketOverCOMET
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class WebSocketOverCOMETRequest extends Generic {

	public $inited = FALSE;
	public $authKey;
	public $type;
	public $reqIdAuthKey;
	public $jsid;
	public $id;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->header('Cache-Control: no-cache, must-revalidate');
		$this->header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
		$this->id = ++$this->appInstance->reqCounter;
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->inited) {
			return;
		}
		$this->inited = true;
		$data         = self::getString($_REQUEST['data']);
		if ($data !== '') {
			$ret = array();
			$id  = self::getString($_REQUEST['_id']);
			if (strpos($id, '.') === false) {
				$ret['error'] = 'Bad cookie.';
			}
			elseif (
				!isset($_REQUEST['data'])
				|| !is_string($_REQUEST['data'])
			) {
				$ret['error'] = 'No data.';
			}
			else {
				list ($workerId, $this->reqIdAuthKey) = explode('.', $id, 2);
				$workerId = (int)$workerId;
				$this->appInstance->directCall($workerId, 'c2s', array($this->reqIdAuthKey, $data));
			}
			if (sizeof($ret)) {
				echo json_encode($ret);
				return;
			}
		}
		/*if ($this->type === 'pull') {
			if (!$this->inited) {
				$this->authKey = sprintf('%x', crc32(microtime() . "\x00" . $this->attrs->server['REMOTE_ADDR']));
				$this->header('Content-Type: text/html; charset=utf-8');
				$this->inited = TRUE;
				$this->out('<!--' . str_repeat('-', 1024) . '->'); // Padding
				$this->out('<script type="text/javascript"> WebSocket.onopen("' . $this->appInstance->ipcId 
					. '.' . $this->id . '.' . $this->authKey 
					. '"); </script>'."\n"
				);

				$appName = self::getString($_REQUEST['_route']);

				if (!isset($this->appInstance->WS->routes[$appName])) {
					if (
						isset(Daemon::$config->logerrors->value) 
						&& Daemon::$config->logerrors->value
					) {
						Daemon::log(__METHOD__ . ': undefined route \'' . $appName . '\'.');
					}

					return;
				}

				if (!$this->downstream = call_user_func($this->appInstance->WS->routes[$appName], $this)) {
					return;
				}
			}

			$this->sleep(1);
		}*/
		if (isset($_REQUEST['_init'])) {
			$this->header('Content-Type: application/x-javascript; charset=utf-8');
			$route = self::getString($_REQUEST['_route']);
			$res   = $this->appInstance->initSession($route, $this);
			if (isset($_REQUEST['_script'])) {
				$q = self::getString($_GET['q']);
				if (ctype_digit($q)) {
					$this->out('Response' . $q . ' = ' . json_encode($res) . ";\n");
				}
			}
			else {
				$this->out(json_encode($res));
			}
			return;
		}
		if (isset($_REQUEST['_poll'])) {
			$this->header('Content-Type: text/plain; charset=utf-8');

			$ret = array();
			$id  = self::getString($_REQUEST['_id']);
			if (strpos($id, '.') === false) {
				$ret['error'] = 'Bad cookie.';
			}
			else {
				list ($workerId, $this->reqIdAuthKey) = explode('.', $id, 2);
				$workerId = (int)$workerId;
				$this->appInstance->directCall($workerId, 'poll', array(
					\PHPDaemon\Core\Daemon::$process->id,
					$this->id,
					$this->reqIdAuthKey,
					self::getString($_REQUEST['ts'])
				));
			}

			if (isset($this->attrs->get['_script'])) {
				$this->header('Content-Type: application/x-javascript; charset=utf-8');
				$q = self::getString($this->attrs->get['q']);

				if (!ctype_digit($q)) {
					$ret['error'] = 'Bad q.';
				}
				$this->jsid = $q;
			}

			if (sizeof($ret)) {
				echo json_encode($ret);
				return;
			}

			$this->out("\n");
			$this->sleep(15);
		}
	}

	/**
	 * Called when the request finished.
	 * @return void
	 */
	public function onFinish() {
		unset($this->appInstance->requests[$this->id]);
	}

}