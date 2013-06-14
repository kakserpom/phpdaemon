<?php
namespace PHPDaemon\Applications;

class WebSocketOverCOMET extends \PHPDaemon\Core\AppInstance {

	public $WS;
	public $requests = array();
	public $sessions = array();
	public $enableRPC = true;
	public $sessCounter = 0;
	public $reqCounter = 0;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->WS = \PHPDaemon\Servers\Websocket\Pool::getInstance();
	}

	public function initSession($route, $req) {
		if (!isset($this->WS->routes[$route])) {
			if (
					isset(\PHPDaemon\Core\Daemon::$config->logerrors)
					&& \PHPDaemon\Core\Daemon::$config->logerrors
			) {
				\PHPDaemon\Core\Daemon::log(__METHOD__ . ': undefined route \'' . $route . '\'.');
			}
			return array('error' => 404);
		}
		$sess = new WebSocketOverCOMETSession(
			$route,
			$this,
			sprintf('%x', crc32(microtime(true) . "\x00" . $req->attrs->server['REMOTE_ADDR']))
		);
		if (!$sess->downstream) {
			return array('error' => 403);
		}
		$sess->server = $req->attrs->server;
		$id           = \PHPDaemon\Core\Daemon::$process->id . '.' . $sess->id . '.' . $sess->authKey;
		return array('id' => $id);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		$req = new WebSocketOverCOMETRequest($this, $upstream, $req);
		return $this->requests[$req->id] = $req;
	}

	public function s2c($reqId, $sessId, $packets, $ts) {
		if (!isset($this->requests[$reqId])) {
			return;
		}
		$req = $this->requests[$reqId];
		if ($req->jsid) {
			$body = 'Response' . $req->jsid . ' = ' . json_encode(array('packets' => $packets)) . ";\n";
		}
		else {
			$body = '<script type="text/javascript">';
			foreach ($packets as $packet) {
				$body .= 'WebSocket.onmessage(' . json_encode(array('type' => $packet[0], 'data' => $packet[1])) . ");\n";
			}
			$body .= "</script>\n";
		}
		$req->out($body);
		$req->finish();
	}

	public function c2s($fullId, $body) {
		list($sessId, $authKey) = explode('.', $fullId, 2);
		$sessId = (int)$sessId;
		if (!isset($this->sessions[$sessId])) {
			return;
		}
		$sess = $this->sessions[$sessId];
		if (!isset($sess->authKey) || $authKey !== $sess->authKey) {
			return;
		}
		if (!isset($sess->downstream)) {
			return;
		}
		$sess->downstream->onFrame($body, \PHPDaemon\Servers\Websocket\Pool::STRING);
		\PHPDaemon\Core\Timer::setTimeout($sess->finishTimer);
	}

	public function poll($pollWorker, $pollReqId, $fullId, $ts) {
		list($sessId, $authKey) = explode('.', $fullId, 2);
		$sessId = (int)$sessId;
		if (!isset($this->sessions[$sessId])) {
			return;
		}
		$sess = $this->sessions[$sessId];
		if (!isset($sess->polling)) {
			return;
		}
		if (!isset($sess->authKey) || $authKey !== $sess->authKey) {
			return;
		}
		$sess->polling->push(array($pollWorker, $pollReqId));
		$sess->flushBufferedPackets($ts);
		\PHPDaemon\Core\Timer::setTimeout($sess->finishTimer);

	}
}

