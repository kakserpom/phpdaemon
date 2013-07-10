<?php
namespace PHPDaemon\Clients\HTTP;

/**
 * Class UploadFile
 * @package PHPDaemon\Clients\HTTP
 */
class UploadFile {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var
	 */
	public $name;
	/**
	 * @var
	 */
	public $data;
	/**
	 * @var
	 */
	public $path;

	/**
	 * @TODO DESCR
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
	 * @TODO DESCR
	 * @param string $str
	 * @return UploadFile
	 */
	public static function fromString($str) {
		$upload       = new self;
		$upload->data = $str;
		return $upload;
	}

	/**
	 * @TODO DESCR
	 * @param string $name
	 * @return $this
	 */
	public function setName($name) {
		$this->name = $name;
		return $this;
	}
}