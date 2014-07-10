<?php
namespace PHPDaemon\Clients\HTTP;

use PHPDaemon\Network\Client;

/**
 * Class Pool
 * @package PHPDaemon\Clients\HTTP
 */
class Pool extends Client {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Default port
			 * @var integer
			 */
			'port'    => 80,

			/**
			 * Default SSL port
			 * @var integer
			 */
			'sslport' => 443,

			/**
			 * Send User-Agent header?
			 * @var boolean
			 */
			'expose'  => 1,
		];
	}

	/**
	 * @TODO DESCR
	 * @param string $url
	 * @param array $params
	 */
	public function get($url, $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = static::parseUrl($url);
		}
		if (isset($params['proxy'])) {
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
	 * @TODO DESCR
	 * @param string $url
	 * @param array $data
	 * @param array $params
	 */
	public function post($url, $data = [], $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = static::parseUrl($url);
		}
		if (isset($params['proxy'])) {
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
	 * @TODO DESCR
	 * @param $mixed
	 * @return bool|string
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
					$url .= $queryDelimiter;
					$queryDelimiter = '';
					$url .= http_build_query($buf);
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
	 * @param $mixed Look Pool::buildUrl()
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
