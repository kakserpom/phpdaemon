<?php
namespace PHPDaemon\SockJS\Methods;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class IFrame extends Generic {
	protected $version = '1.0.3';
	protected $contentType = 'text/html';
	protected $cacheable = true;

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		parent::init();
		if (isset($this->attrs->version)) {
			$this->version = $this->attrs->version;
		}
		$this->header('Cache-Control: max-age=31536000, public, pre-check=0, post-check=0');
		$this->header('Expires: '.date('r', strtotime('+1 year')));
		$html = '<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<script>
		document.domain = document.domain;
		_sockjs_onload = function(){SockJS.bootstrap_iframe();};
	</script>
	<script src="https://cdn.jsdelivr.net/sockjs/' . htmlentities($this->version, ENT_QUOTES, 'UTF-8').'/sockjs.min.js"></script>
</head>
<body>
	<h2>Don\'t panic!</h2>
	<p>This is a SockJS hidden iframe. It\'s used for cross domain magic.</p>
</body>
</html>';
		$etag = 'W/"'.sha1($html).'"';
		$this->header('ETag: '.$etag);	
		if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
			if ($_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
				$this->status(304);
				$this->removeHeader('Content-Type');
				$this->finish();
				return;
			}
		}
		$this->header('Content-Length: '.strlen($html));
		echo $html;
		$this->finish();
	}

	/**
	 * Called when request iterated
	 * @return void
	 */
	public function run() {}
}
