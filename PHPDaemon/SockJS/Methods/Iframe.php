<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class IFrame extends Generic {
	protected $version = '0.3';
	protected $contentType = 'text/html';
	protected $cacheable = true;


	/**
	 * Sets version
	 * @param string $val
	 * @return void
	 */
	public function setVersion($val) {
		$this->version = $val;
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		parent::init();
		$this->header('Cache-Control: max-age=31536000, public, pre-check=0, post-check=0, no-transform');
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
  <script src="http://cdn.sockjs.org/sockjs-' . htmlentities($this->version, ENT_QUOTES, 'UTF-8').'.min.js"></script>
</head>
<body>
  <h2>Don\'t panic!</h2>
  <p>This is a SockJS hidden iframe. It\'s used for cross domain magic.</p>
</body>
</html>';
		$etag = 'W/"'.sha1($html).'"';
		$this->header('Content-Length: '.strlen($html));
		$this->header('ETag: '.$etag);	
		if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
			if ($_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
				$this->status(304);
				$this->finish();
				return;
			}
		}
		echo $html;
		$this->finish();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {}

}
