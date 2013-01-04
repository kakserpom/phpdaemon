<?php
/**
 * @package NetworkClients
 * @subpackage HTTPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTPClient extends NetworkClient {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'port' => 80,
			'expose' => 1,
		);
	}

	public function get($url, $params) {
		if (is_callable($params)) {
			$params = array('resultcb' => $params);
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['host'], $params['uri'], $params['port']) = HTTPClient::prepareUrl($url);
		}
		$this->getConnection(
			$addr = $params['host'] . (isset($params['port']) ? $params['port'] : null),
			function($conn) use ($url, $params) {
				$conn->get($url, $params);
			}
		);
	}

	public function post($url, $data = array(), $params) {
		if (is_callable($params)) {
			$params = array('resultcb' => $params);
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['host'], $params['uri'], $params['port']) = HTTPClient::prepareUrl($url);
		}
		$this->getConnection(
			$addr = $params['host'] . (isset($params['port']) ? $params['port'] : null),
			function($conn) use ($url, $data, $params) {
				$conn->post($url, $data, $params);
			}
		);
	}


	public static function prepareUrl($mixed) {
		if (is_string($mixed)) {
			$url = $mixed;
		}
		elseif (is_array($mixed)) {
			$url = '';
			$buf = array();
			$queryDelimiter = '?';
			$mixed[] = '';
			foreach ($mixed as $k => $v) {
				if (is_int($k) || ctype_digit($k)) {
					if (sizeof($buf) > 0) {
						$url .= $queryDelimiter;
						$queryDelimiter = '';
						$url .= http_build_query($buf);
					}
					$url .= $v; 
				} else {
					$buf[$k] = $v;
				}
			}
		}
		else {
			return false;
		}
		$u = parse_url($url);
		$uri = '';
		if (isset($u['path'])) {
			$uri .= $u['path'];
			if (isset($u['query'])) {
				$uri .= '?'.$u['query'];
			}
		}
		return array($u['host'], $uri, isset($u['port']) ? $u['port'] : null);
	}
}
class HTTPClientConnection extends NetworkClientConnection {

	const STATE_HEADERS = 1;
	const STATE_BODY = 2;
	public $headers = array();
	public $contentLength = -1;
	public $body = '';
	public $EOL = "\r\n";
	public $cookie = array();
	public $keepalive = false;
	public $curChunkSize;
	public $curChunk;
	public $chunked = false;
	public $protocolError;
	
	public function get($url, $params = null) {
		if (!is_array($params)) {
			$params = array('resultcb' => $params);
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
		$this->writeln('GET '.$params['uri']. ' HTTP/'.$params['version']);
		$this->writeln('Host: '.$params['host']);
		if ($this->pool->config->expose->value) {
			$this->writeln('User-Agent: phpDaemon/'.Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: '.http_build_query($this->cookie, '', '; '));
		}
		if (isset($params['headers'])) {
			$this->customRequestHeaders($params['headers']);
		}
		$this->writeln('');
		$this->headers = array();
		$this->body = '';
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
					$this->writeln($item[0].': '.$item[1]); // @TODO: prevent injections?
				}
			}
			else {
				$this->writeln($key.': '.$item);
			}
		}
	}

	public function post($url, $data = array(), $params = null) {
		if (!is_array($params)) {
			$params = array('resultcb' => $params);
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
		$this->writeln('POST '.$params['uri']. ' HTTP/'.$params['version']);
		$this->writeln('Host: '.$params['host']);
		if ($this->pool->config->expose->value) {
			$this->writeln('User-Agent: phpDaemon/'.Daemon::$version);
		}
		if (isset($params['cookie']) && sizeof($params['cookie'])) {
			$this->writeln('Cookie: '.http_build_query($this->cookie, '', '; '));
		}
		$body = '';
		foreach ($data as $k => $v) {
			if (is_object($v) && $v instanceof HTTPClientUpload) {
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
		$this->headers = array();
		$this->body = '';
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
					$this->contentLength = (int) $this->headers['HTTP_CONTENT_LENGTH'];
				} else {
					$this->contentLength = -1;
				}
				if (isset($this->headers['HTTP_TRANSFER_ENCODING'])) {
					$e = explode(', ', strtolower($this->headers['HTTP_TRANSFER_ENCODING']));
					$this->chunked = in_array('chunked', $e, true);
				} else {
					$this->chunked = false;
				}
				if (isset($this->headers['HTTP_CONNECTION'])) {
					$e = explode(', ', strtolower($this->headers['HTTP_CONNECTION']));
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
				$this->state = self::STATE_HEADERS;
			} elseif ($this->state === self::STATE_HEADERS) {
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
					} else { // protocol error
						$this->protocolError = __LINE__;
						$this->finish();
						return;
					}
				}
				$len = strlen($this->buf);
				$n = $this->curChunkSize - strlen($this->curChunk);
				if ($n >= $len) {
					$this->curChunk .= $this->buf;
					$this->buf = '';
				} else {
					$this->curChunk .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n);
				}
				if ($this->curChunkSize <= strlen($this->curChunk)) {
					$this->body .= $this->curChunk;
					$this->curChunkSize = null;	
					$this->curChunk = '';
					goto chunk;
				}
			}

		} else {
			$this->body .= $buf;
			if (($this->contentLength !== -1) && (strlen($this->body) >= $this->contentLength)) {
				$this->requestFinished();
			}
		}
	}

	public function onFinish() {
		if ($this->protocolError) {
			$this->executeAll($this, false);
		} else {
			if (($this->state !== self::STATE_ROOT) && !$this->onResponse->isEmpty()) {
				$this->requestFinished();
			}
		}
		parent::onFinish();
	}

	public function requestFinished() {
		$this->onResponse->executeOne($this, true);
		$this->state = self::STATE_ROOT;
		$this->contentLength = -1;
		$this->curChunkSize = null;
		$this->chunked = false;
		$this->headers = array();
		$this->body = '';
		if (!$this->keepalive) {
			$this->finish();
		}
		$this->checkFree();
	}
}
class HTTPClientUpload {
	public $name;
	public $data;
	public $path;
	public static function fromFile($path) {
		$upload = new self;
		$upload->path = $path;
		$upload->name = basename($path);
		return $upload;
	}
	public static function fromString($str) {
		$upload = new self;
		$upload->data = $str;
		return $upload;
	}
	public function setName($name) {
		$this->name = $name;
		return $this;
	}
}

