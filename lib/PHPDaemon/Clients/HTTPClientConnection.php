<?php
namespace PHPDaemon\Clients;

use PHPDaemon\Daemon;
use PHPDaemon\HTTPRequest;

/**
 * @package    NetworkClients
 * @subpackage HTTPClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class HTTPClientConnection extends NetworkClientConnection {

	const STATE_HEADERS = 1;
	const STATE_BODY    = 2;
	public $headers = [];
	public $contentLength = -1;
	public $body = '';
	protected $EOL = "\r\n";
	public $cookie = [];
	public $keepalive = false;
	public $curChunkSize;
	public $curChunk;
	public $chunked = false;
	public $protocolError;
	public $responseCode = 0;

	public function get($url, $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = HTTPClient::prepareUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			list ($params['host'], $params['uri']) = $prepared;
		}
		if (!isset($params['version'])) {
			$params['version'] = '1.1';
		}
		$this->writeln('GET ' . $params['uri'] . ' HTTP/' . $params['version']);
		$this->writeln('Host: ' . $params['host']);
		if ($this->pool->config->expose->value) {
			$this->writeln('User-Agent: phpDaemon/' . Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: ' . http_build_query($this->cookie, '', '; '));
		}
		if (isset($params['headers'])) {
			$this->customRequestHeaders($params['headers']);
		}
		$this->writeln('');
		$this->onResponse->push($params['resultcb']);
		$this->checkFree();
	}

	protected function customRequestHeaders($headers) {
		foreach ($headers as $key => $item) {
			if (is_numeric($key)) {
				if (is_string($item)) {
					$this->writeln($item);
				}
				elseif (is_array($item)) {
					$this->writeln($item[0] . ': ' . $item[1]); // @TODO: prevent injections?
				}
			}
			else {
				$this->writeln($key . ': ' . $item);
			}
		}
	}

	public function post($url, $data = [], $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = HTTPClient::prepareUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			list ($params['host'], $params['uri']) = $prepared;
		}
		if (!isset($params['version'])) {
			$params['version'] = '1.1';
		}
		if (!isset($params['contentType'])) {
			$params['contentType'] = 'application/x-www-form-urlencoded';
		}
		$this->writeln('POST ' . $params['uri'] . ' HTTP/' . $params['version']);
		$this->writeln('Host: ' . $params['host']);
		if ($this->pool->config->expose->value) {
			$this->writeln('User-Agent: phpDaemon/' . Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: ' . http_build_query($this->cookie, '', '; '));
		}
		foreach ($data as $val) {
			if (is_object($val) && $val instanceof HTTPClientUpload) {
				$params['contentType'] = 'multipart/form-data';
			}
		}
		$this->writeln('Content-Type: ' . $params['contentType']);
		$body = http_build_query($data, '&', PHP_QUERY_RFC3986);
		$this->writeln('Content-Length: ' . strlen($body));
		if (isset($params['headers'])) {
			$this->customRequestHeaders($params['headers']);
		}
		$this->writeln('');
		$this->write($body);
		$this->onResponse->push($params['resultcb']);
		$this->checkFree();
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		if ($this->state === self::STATE_BODY) {
			goto body;
		}
		$this->buf .= $buf;
		$buf = '';
		while (($line = $this->gets()) !== FALSE) {
			if ($line === $this->EOL) {
				if (isset($this->headers['HTTP_CONTENT_LENGTH'])) {
					$this->contentLength = (int)$this->headers['HTTP_CONTENT_LENGTH'];
				}
				else {
					$this->contentLength = -1;
				}
				if (isset($this->headers['HTTP_TRANSFER_ENCODING'])) {
					$e             = explode(', ', strtolower($this->headers['HTTP_TRANSFER_ENCODING']));
					$this->chunked = in_array('chunked', $e, true);
				}
				else {
					$this->chunked = false;
				}
				if (isset($this->headers['HTTP_CONNECTION'])) {
					$e               = explode(', ', strtolower($this->headers['HTTP_CONNECTION']));
					$this->keepalive = in_array('keep-alive', $e, true);
				}
				if (!$this->chunked) {
					$this->body .= $this->buf;
					$this->buf = '';
				}
				$this->state = self::STATE_BODY;
				break;
			}
			if ($this->state === self::STATE_ROOT) {
				$this->headers['STATUS'] = rtrim($line);
				$e                       = explode(' ', $this->headers['STATUS']);
				$this->responseCode      = isset($e[1]) ? (int)$e[1] : 0;
				$this->state             = self::STATE_HEADERS;
			}
			elseif ($this->state === self::STATE_HEADERS) {
				$e = explode(': ', rtrim($line));

				if (isset($e[1])) {
					$this->headers['HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr))] = $e[1];
				}
			}
		}
		if ($this->state !== self::STATE_BODY) {
			return; // not enough data yet
		}
		body:

		if ($this->chunked) {
			$this->buf .= $buf;
			chunk:
			if ($this->curChunkSize === null) { // outside of chunk
				$l = $this->gets();
				if ($l === $this->EOL) { // skip empty line
					goto chunk;
				}
				if ($l === false) {
					return; // not enough data yet
				}
				$l = rtrim($l);
				if (!ctype_xdigit($l)) {
					$this->protocolError = __LINE__;
					$this->finish(); // protocol error
					return;
				}
				$this->curChunkSize = hexdec($l);
			}
			if ($this->curChunkSize !== null) {
				if ($this->curChunkSize === 0) {
					if ($this->gets() === $this->EOL) {
						$this->requestFinished();
						return;
					}
					else { // protocol error
						$this->protocolError = __LINE__;
						$this->finish();
						return;
					}
				}
				$len = strlen($this->buf);
				$n   = $this->curChunkSize - strlen($this->curChunk);
				if ($n >= $len) {
					$this->curChunk .= $this->buf;
					$this->buf = '';
				}
				else {
					$this->curChunk .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n);
				}
				if ($this->curChunkSize <= strlen($this->curChunk)) {
					$this->body .= $this->curChunk;
					$this->curChunkSize = null;
					$this->curChunk     = '';
					goto chunk;
				}
			}

		}
		else {
			$this->body .= $buf;
			if (($this->contentLength !== -1) && (strlen($this->body) >= $this->contentLength)) {
				$this->requestFinished();
			}
		}
	}

	public function onFinish() {
		if ($this->protocolError) {
			$this->executeAll($this, false);
		}
		else {
			if (($this->state !== self::STATE_ROOT) && !$this->onResponse->isEmpty()) {
				$this->requestFinished();
			}
		}
		parent::onFinish();
	}

	public function requestFinished() {
		$this->onResponse->executeOne($this, true);
		$this->state         = self::STATE_ROOT;
		$this->contentLength = -1;
		$this->curChunkSize  = null;
		$this->chunked       = false;
		$this->headers       = [];
		$this->body          = '';
		$this->responseCode  = 0;
		if (!$this->keepalive) {
			$this->finish();
		}
		$this->checkFree();
	}
}