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
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * State: headers
	 */
	const STATE_HEADERS = 1;

	/**
	 * State: body
	 */
	const STATE_BODY    = 2;

	/**
	 * @var array Associative array of headers
	 */
	public $headers = [];

	/**
	 * @var integer Content length
	 */
	public $contentLength = -1;

	/**
	 * @var string Contains response body
	 */
	public $body = '';

	/**
	 * @var string End of line
	 */
	protected $EOL = "\r\n";

	/**
	 * @var array Associative array of Cookies
	 */
	public $cookie = [];

	/**
	 * @var integer Size of current chunk
	 */
	protected $curChunkSize;

	/**
	 * @var string
	 */
	protected $curChunk;

	/**
	 * @var boolean
	 */
	public $chunked = false;

	/**
	 * @var callback
	 */
	public $chunkcb;

	/**
	 * @var integer
	 */
	public $protocolError;

	/**
	 * @var integer
	 */
	public $responseCode = 0;

	/**
	 * @var string Last requested URL
	 */
	public $lastURL;

	/**
	 * @var array Raw headers array
	 */
	public $rawHeaders = null;

	public $contentType;

	public $charset;

	public $eofTerminated = false;

	/**
	 * Performs GET-request
	 * @param string $url
	 * @param array  $params
	 */
	public function get($url, $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = Pool::parseUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			list ($params['host'], $params['uri']) = $prepared;
		}
		if ($params['uri'] === '') {
			$params['uri'] = '/';
		}
		$this->lastURL = 'http://' . $params['host'] . $params['uri'];
		if (!isset($params['version'])) {
			$params['version'] = '1.1';
		}
		$this->writeln('GET ' . $params['uri'] . ' HTTP/' . $params['version']);
		if (isset($params['proxy'])) {
			if (isset($params['proxy']['auth'])) {
				$this->writeln('Proxy-Authorization: basic ' . base64_encode($params['proxy']['auth']['username'] . ':' . $params['proxy']['auth']['password']));
			}
		}
		$this->writeln('Host: ' . $params['host']);
		if ($this->pool->config->expose->value && !isset($params['headers']['User-Agent'])) {
			$this->writeln('User-Agent: phpDaemon/' . Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: ' . http_build_query($params['cookie'], '', '; '));
		}
		if (isset($params['headers'])) {
			$this->customRequestHeaders($params['headers']);
		}
		if (isset($params['rawHeaders']) && $params['rawHeaders']) {
			$this->rawHeaders = [];
		}
		if(isset($params['chunkcb']) && is_callable($params['chunkcb'])) {
			$this->chunkcb = $params['chunkcb'];
		}
		$this->writeln('');
		$this->onResponse($params['resultcb']);
		$this->checkFree();
	}

	/**
	 * @param array $headers
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
	 * Performs POST-request
	 * @param string $url
	 * @param array  $data
	 * @param array  $params
	 */
	public function post($url, $data = [], $params = null) {
		if (!is_array($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			$prepared = Pool::parseUrl($url);
			if (!$prepared) {
				if (isset($params['resultcb'])) {
					call_user_func($params['resultcb'], false);
				}
				return;
			}
			list ($params['host'], $params['uri']) = $prepared;
		}
		if ($params['uri'] === '') {
			$params['uri'] = '/';
		}
		$this->lastURL = 'http://' . $params['host'] . $params['uri'];		
		if (!isset($params['version'])) {
			$params['version'] = '1.1';
		}
		if (!isset($params['contentType'])) {
			$params['contentType'] = 'application/x-www-form-urlencoded';
		}
		$this->writeln('POST ' . $params['uri'] . ' HTTP/' . $params['version']);
		if (isset($params['proxy'])) {
			if (isset($params['proxy']['auth'])) {
				$this->writeln('Proxy-Authorization: basic ' . base64_encode($params['proxy']['auth']['username'] . ':' . $params['proxy']['auth']['password']));
			}
		}
		if (!isset($params['keepalive']) || !$params['keepalive']) {
			$this->writeln('Connection: close');
		}
		$this->writeln('Host: ' . $params['host']);
		if ($this->pool->config->expose->value && !isset($params['headers']['User-Agent'])) {
			$this->writeln('User-Agent: phpDaemon/' . Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: ' . http_build_query($params['cookie'], '', '; '));
		}
		foreach ($data as $val) {
			if (is_object($val) && $val instanceof UploadFile) {
				$params['contentType'] = 'multipart/form-data';
			}
		}
		$this->writeln('Content-Type: ' . $params['contentType']);
		if ($params['contentType'] === 'application/x-www-form-urlencoded') {
			$body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
		} elseif ($params['contentType'] === 'application/x-json') {
			$body = json_encode($data);
		} else {
			$body = 'unsupported Content-Type';
		}
		$this->writeln('Content-Length: ' . strlen($body));
		if (isset($params['headers'])) {
			$this->customRequestHeaders($params['headers']);
		}
		if (isset($params['rawHeaders']) && $params['rawHeaders']) {
			$this->rawHeaders = [];
		}
		$this->writeln('');
		$this->write($body);
		$this->writeln('');
		$this->onResponse($params['resultcb']);
	}

	/**
	 * Get body
	 * @return string
	 */
	public function getBody() {
		return $this->body;
	}

	/**
	 * Get headers
	 * @return array
	 */
	public function getHeaders() {
		return $this->headers;
	}

	/**
	 * Get header
	 * @param  string $name Header name
	 * @return string
	 */
	public function getHeader($name) {
		$k = 'HTTP_' . strtoupper(strtr($name, Generic::$htr));
		return isset($this->headers[$k]) ? $this->headers[$k] : null;
	}

	/**
	 * Called when new data received
	 */
	public function onRead() {
		if ($this->state === self::STATE_BODY) {
			goto body;
		}
		while (($line = $this->readLine()) !== null) {
			if ($line !== '') {
				if ($this->rawHeaders !== null) {
					$this->rawHeaders[] = $line;
				}
			}
			else {
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
				if (isset($this->headers['HTTP_CONTENT_TYPE'])) {
					parse_str('type='.strtr($this->headers['HTTP_CONTENT_TYPE'], [';' => '&', ' ' => '']), $p);
					$this->contentType = $p['type'];
					if (isset($p['charset'])) {
						$this->charset = strtolower($p['charset']);
					}
				}
				if ($this->contentLength === -1 && !$this->chunked && !$this->keepalive) {
					$this->eofTerminated = true;
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
					$k = 'HTTP_' . strtoupper(strtr($e[0], Generic::$htr));
					if ($k === 'HTTP_SET_COOKIE') {
						parse_str(strtr($e[1], [';' => '&', ' ' => '']), $p);
						if (sizeof($p)) {
							$this->cookie[$k = key($p)] =& $p;
							$p['value'] = $p[$k];
							unset($p[$k], $p);
						}
					}
					if (isset($this->headers[$k])) {
						if (is_array($this->headers[$k])) {
							$this->headers[$k][] = $e[1];
						} else {
							$this->headers[$k] = [$this->headers[$k], $e[1]];
						}
					} else {
						$this->headers[$k] = $e[1];
					}
				}
			}
		}
		if ($this->state !== self::STATE_BODY) {
			return; // not enough data yet
		}
		body:
		if ($this->eofTerminated) {
			$body = $this->readUnlimited();
			if($this->chunkcb) {
				call_user_func($this->chunkcb, $body);
			}
			$this->body .= $body;
			return;
		}
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
					if($this->chunkcb) {
						call_user_func($this->chunkcb, $this->curChunk);
					}
					$this->body .= $this->curChunk;
					$this->curChunkSize = null;
					$this->curChunk     = '';
					goto chunk;
				}
			}

		}
		else {
			$body = $this->read($this->contentLength - strlen($this->body));
			if($this->chunkcb) {
				call_user_func($this->chunkcb, $body);
			}
			$this->body .= $body;
			if (($this->contentLength !== -1) && (strlen($this->body) >= $this->contentLength)) {
				$this->requestFinished();
			}
		}
	}

	/**
	 * Called when connection finishes
	 */
	public function onFinish() {
		if ($this->eofTerminated) {
			$this->requestFinished();
			$this->onResponse->executeAll($this, false);
			parent::onFinish();
			return;
		}
		if ($this->protocolError) {
			$this->onResponse->executeAll($this, false);
		}
		else {
			if (($this->state !== self::STATE_ROOT) && !$this->onResponse->isEmpty()) {
				$this->requestFinished();
			}
		}
		parent::onFinish();
	}

	/**
	 * Called when request is finished
	 */
	protected function requestFinished() {
		$this->onResponse->executeOne($this, true);
		$this->state         = self::STATE_ROOT;
		$this->contentLength = -1;
		$this->curChunkSize  = null;
		$this->chunked       = false;
		$this->eofTerminated = false;
		$this->headers       = [];
		$this->rawHeaders    = null;
		$this->contentType   = null;
		$this->charset       = null;
		$this->body          = '';
		$this->responseCode  = 0;
		if (!$this->keepalive) {
			$this->finish();
		}
		$this->checkFree();
	}
}
