<?php
namespace PHPDaemon\Clients\HTTP;

/**
 * Class UploadFile
 * @package PHPDaemon\Clients\HTTP
 */
class UploadFile {
	use \PHPDaemon\Traits\ClassWatchdog;

	public $name;
	public $data;
	public $path;

	/**
	 * @param string $path
	 * @return UploadFile
	 */
	public static function fromFile($path) {
		$upload       = new self;
		$upload->path = $path;
		$upload->name = basename($path);
		return $upload;
	}

	/**
	 * @param string $str
	 * @return UploadFile
	 */
	public static function fromString($str) {
		$upload       = new self;
		$upload->data = $str;
		return $upload;
	}

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setName($name) {
		$this->name = $name;
		return $this;
	}
}