<?php
namespace PHPDaemon\Clients\HTTP;

use PHPDaemon\Network\Client;

/**
 * @package    NetworkClients
 * @subpackage HTTPClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends Client {

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [integer] Default port */
			'port'    => 80,

			/* [integer] Default SSL port */
			'sslport' => 443,

			/* [boolean] Send User-Agent header? */
			'expose'  => 1,
		];
	}

	/**
	 * Performs GET-request
	 * @param string   $url
	 * @param array    $params
	 * @param callable $resultcb
	 * @call  ( url $url, array $params )
	 * @call  ( url $url, callable $resultcb )
	 * @callback $resultcb ( Connection $conn, boolean $success )
	 */
	public function get($url, $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = static::parseUrl($url);
		}
		if (isset($params['connect'])) {
			$dest = $params['connect'];
		}
		elseif (isset($params['proxy']) && $params['proxy']) {
			if ($params['proxy']['type'] === 'http') {
				$dest = 'tcp://' . $params['proxy']['addr'];
			}
		}
		else {
			$dest = 'tcp://' . $params['host'] . (isset($params['port']) ? ':' . $params['port'] : null) . ($params['scheme'] === 'https' ? '#ssl' : '');
		}
		$this->getConnection($dest,	function ($conn) use ($url, $params) {
			if (!$conn->isConnected()) {
				call_user_func($params['resultcb'], false);
				return;
			}
			$conn->get($url, $params);
		});
	}

	/**
	 * Performs HTTP request
	 * @param string   $url
	 * @param array    $data
	 * @param array    $params
	 * @param callable $resultcb
	 * @call  ( url $url, array $data, array $params )
	 * @call  ( url $url, array $data, callable $resultcb )
	 * @callback $resultcb ( Connection $conn, boolean $success )
	 */
	public function post($url, $data = [], $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = static::parseUrl($url);
		}
		if (isset($params['connect'])) {
			$dest = $params['connect'];
		}
		elseif (isset($params['proxy']) && $params['proxy']) {
			if ($params['proxy']['type'] === 'http') {
				$dest = 'tcp://' . $params['proxy']['addr'];
			}
		}
		else {
			$dest = 'tcp://' . $params['host'] . (isset($params['port']) ? ':' . $params['port'] : null) . ($params['scheme'] === 'https' ? '#ssl' : '');
		}
		$this->getConnection($dest, function ($conn) use ($url, $data, $params) {
			if (!$conn->isConnected()) {
				call_user_func($params['resultcb'], false);
				return;
			}
			$conn->post($url, $data, $params);
		});
	}

	/**
	 * Builds URL from array
	 * @param string $mixed
	 * @call  ( string $str )
	 * @call  ( array $mixed )
	 * @return string|false
	 */
	public static function buildUrl($mixed) {
		if (is_string($mixed)) {
			return $mixed;
		}
		if (!is_array($mixed)) {
			return false;
		}
		$url            = '';
		$buf            = [];
		$queryDelimiter = '?';
		$mixed[]        = '';
		foreach ($mixed as $k => $v) {
			if (is_int($k) || ctype_digit($k)) {
				if (sizeof($buf) > 0) {
					if (strpos($url, '?') !== false) {
						$queryDelimiter = '&';
					}
					$url .= $queryDelimiter . http_build_query($buf);
					$queryDelimiter = '';
				}
				$url .= $v;
			}
			else {
				$buf[$k] = $v;
			}
		}
		return $url;
	}

	/**
	 * Parse URL
	 * @param string $mixed Look Pool::buildUrl()
	 * @call  ( string $str )
	 * @call  ( array $mixed )
	 * @return array|bool
	 */
	public static function parseUrl($mixed) {
		$url = static::buildUrl($mixed);
		if (false === $url) {
			return false;
		}
		$u   = parse_url($url);
		$uri = '';
		if (isset($u['path'])) {
			$uri .= $u['path'];
			if (isset($u['query'])) {
				$uri .= '?' . $u['query'];
			}
		}
		return [$u['scheme'], $u['host'], $uri, isset($u['port']) ? $u['port'] : null];
	}
}
