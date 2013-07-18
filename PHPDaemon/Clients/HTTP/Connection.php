<?php
namespace PHPDaemon\Clients\HTTP;

use PHPDaemon\Clients\HTTP\Pool;
use PHPDaemon\Clients\HTTP\UploadFile;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Network\ClientConnection;

/**
 * @package    NetworkClients
 * @subpackage HTTPClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * State: headers
	 * @var integer
	 */
	const STATE_HEADERS = 1;
	/**
	 * State: body
	 * @var integer
	 */
	const STATE_BODY    = 2;
	/**
	 * @var array
	 */
	public $headers = [];
	/**
	 * @var int
	 */
	public $contentLength = -1;
	/**
	 * @var string
	 */
	public $body = '';
	/**
	 * @var string
	 */
	protected $EOL = "\r\n";
	/**
	 * @var array
	 */
	public $cookie = [];
	/**
	 * @var bool
	 */
	public $keepalive = false;
	/**
	 * @var
	 */
	public $curChunkSize;
	/**
	 * @var
	 */
	public $curChunk;
	/**
	 * @var bool
	 */
	public $chunked = false;
	/**
	 * @var
	 */
	public $protocolError;
	/**
	 * @var int
	 */
	public $responseCode = 0;

	/**
	 * Last requested URL
	 * @var string
	 */
	public $lastURL;

	/**
	 * @param string $url
	 * @param array $params
	 */
	public function get($url, $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = Pool::prepareUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			$this->lastURL = 'http://' . $params['host'] . $params['uri'];
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

	/**
	 * @param $headers
	 */
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

	/**
	 * @param string $url
	 * @param array $data
	 * @param array $params
	 */
	public function post($url, $data = [], $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = Pool::prepareUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			$this->lastURL = 'http://' . $params['host'] . $params['uri'];
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
			if (is_object($val) && $val instanceof UploadFile) {
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
	public function onRead() {
		if ($this->state === self::STATE_BODY) {
			goto body;
		}
		while (($line = $this->readLine()) !== null) {
			if ($line === '') {
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
				$this->state = self::STATE_BODY;
				break;
			}
			if ($this->state === self::STATE_ROOT) {
				$this->headers['STATUS'] = $line;
				$e                       = explode(' ', $this->headers['STATUS']);
				$this->responseCode      = isset($e[1]) ? (int)$e[1] : 0;
				$this->state             = self::STATE_HEADERS;
			}
			elseif ($this->state === self::STATE_HEADERS) {
				$e = explode(': ', $line);

				if (isset($e[1])) {
					$this->headers['HTTP_' . strtoupper(strtr($e[0], Generic::$htr))] = $e[1];
				}
			}
		}
		if ($this->state !== self::STATE_BODY) {
			return; // not enough data yet
		}
		body:

		if ($this->chunked) {
			chunk:
			if ($this->curChunkSize === null) { // outside of chunk
				$l = $this->readLine();
				if ($l === '') { // skip empty line
					goto chunk;
				}
				if ($l === null) {
					return; // not enough data yet
				}
				if (!ctype_xdigit($l)) {
					$this->protocolError = __LINE__;
					$this->finish(); // protocol error
					return;
				}
				$this->curChunkSize = hexdec($l);
			}
			if ($this->curChunkSize !== null) {
				if ($this->curChunkSize === 0) {
					if ($this->readLine() === '') {
						$this->requestFinished();
						return;
					}
					else { // protocol error
						$this->protocolError = __LINE__;
						$this->finish();
						return;
					}
				}
				$n   = $this->curChunkSize - strlen($this->curChunk);
				$this->curChunk .= $this->read($n);
				if ($this->curChunkSize <= strlen($this->curChunk)) {
					$this->body .= $this->curChunk;
					$this->curChunkSize = null;
					$this->curChunk     = '';
					goto chunk;
				}
			}

		}
		else {
			$this->body .= $this->read($this->contentLength - strlen($this->body));
			if (($this->contentLength !== -1) && (strlen($this->body) >= $this->contentLength)) {
				$this->requestFinished();
			}
		}
	}

	/**
	 * @TODO DESCR
	 */
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

	/**
	 * @TODO DESCR
	 */
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