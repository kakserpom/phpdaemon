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
	 * @param string $url
	 * @param array $params
	 */
	public function get($url, $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = self::prepareUrl($url);
		}
		$ssl = $params['scheme'] === 'https';
		$this->getConnection(
			'tcp://' . $params['host'] . (isset($params['port']) ? ':' . $params['port'] : null) . ($ssl ? '#ssl' : ''),
			function ($conn) use ($url, $params) {
				$conn->get($url, $params);
			}
		);
	}

	/**
	 * @param string $url
	 * @param array $data
	 * @param array $params
	 */
	public function post($url, $data = [], $params) {
		if (is_callable($params)) {
			$params = ['resultcb' => $params];
		}
		if (!isset($params['uri']) || !isset($params['host'])) {
			list ($params['scheme'], $params['host'], $params['uri'], $params['port']) = self::prepareUrl($url);
		}
		$ssl = $params['scheme'] === 'https';
		$this->getConnection(
			'tcp://' . $params['host'] . (isset($params['port']) ? ':' . $params['port'] : null) . ($ssl ? '#ssl' : ''),
			function ($conn) use ($url, $data, $params) {
				$conn->post($url, $data, $params);
			}
		);
	}

	/**
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
	 * @param $mixed
	 * @return array|bool
	 */
	public static function prepareUrl($mixed) {
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
