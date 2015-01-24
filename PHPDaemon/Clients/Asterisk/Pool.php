<?php
namespace PHPDaemon\Clients\Asterisk;

use PHPDaemon\Network\Client;

/**
 * Class Pool
 * @package PHPDaemon\Clients\Asterisk
 */
class Pool extends Client {

	/**
	 * @var array Asterisk Call Manager Interface versions for each session
	 */
	protected $amiVersions = [];

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array
	 */
	protected function getConfigDefaults() {
		return [
			// [string] Auth hash type
			'authtype' => 'md5',
			// [integer] Port
			'port'     => 5280,
		];
	}

	/**
	 * @var array Beginning of the string in the header or value that indicates whether the save value case
	 */
	public static $safeCaseValues = ['dialstring', 'callerid', 'connectedline'];

	/**
	 * Sets AMI version
	 * @param  string $addr Address
	 * @param  string $ver  Version
	 * @return void
	 */
	public function setAmiVersion($addr, $ver) {
		$this->amiVersions[$addr] = $ver;
	}

	/**
	 * Prepares environment scope
	 * @param  string $data Address
	 * @return array
	 */
	public static function prepareEnv($data) {
		$result = [];
		$rows   = explode("\n", $data);
		for ($i = 0, $s = sizeof($rows); $i < $s; ++$i) {
			$e             = self::extract($rows[$i]);
			$result[$e[0]] = $e[1];
		}
		return $result;
	}

	/**
	 * Extract key and value pair from line.
	 * @param  string $line
	 * @return array
	 */
	public static function extract($line) {
		$e      = explode(': ', $line, 2);
		$header = strtolower(trim($e[0]));
		$value  = isset($e[1]) ? trim($e[1]) : null;
		$safe   = false;

		foreach (self::$safeCaseValues as $item) {
			if (strncasecmp($header, $item, strlen($item)) === 0) {
				$safe = true;
				break;
			}
			if (strncasecmp($value, $item, strlen($item)) === 0) {
				$safe = true;
				break;
			}
		}

		if (!$safe) {
			$value = strtolower($value);
		}

		return [$header, $value];
	}
}
