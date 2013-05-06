<?php
namespace PHPDaemon\Clients;

class HTTPClientUpload {
	public $name;
	public $data;
	public $path;

	public static function fromFile($path) {
		$upload       = new self;
		$upload->path = $path;
		$upload->name = basename($path);
		return $upload;
	}

	public static function fromString($str) {
		$upload       = new self;
		$upload->data = $str;
		return $upload;
	}

	public function setName($name) {
		$this->name = $name;
		return $this;
	}
}