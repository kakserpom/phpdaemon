<?php
class FileReader extends AppInstance {

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'indexfiles'	=> 'index.html/index.htm',
		));

		$this->indexFiles = explode('/', $this->config->indexfiles->value);
	}

	/**
	 * @method beginRequest
	 * @description Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new FileReaderRequest($this, $upstream, $req);
	}
}

class FileReaderRequest extends HTTPRequest {

	public $stream;
	
	private static $fileTypes = array(
		'txt'  => 'text/plain',
		'htm'  => 'text/html',
		'html' => 'text/html',
		'php'  => 'text/html',
		'css'  => 'text/css',
		'js'   => 'application/javascript',
		'json' => 'application/json',
		'xml'  => 'application/xml',
		'swf'  => 'application/x-shockwave-flash',
		'flv'  => 'video/x-flv',

		// images
		'png'  => 'image/png',
		'jpe'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'gif'  => 'image/gif',
		'bmp'  => 'image/bmp',
		'ico'  => 'image/vnd.microsoft.icon',
		'tiff' => 'image/tiff',
		'tif'  => 'image/tiff',
		'svg'  => 'image/svg+xml',
		'svgz' => 'image/svg+xml',

		// archives
		'zip' => 'application/zip',
		'rar' => 'application/x-rar-compressed',
		'exe' => 'application/x-msdownload',
		'msi' => 'application/x-msdownload',
		'cab' => 'application/vnd.ms-cab-compressed',

		// audio/video
		'mp3' => 'audio/mpeg',
		'qt'  => 'video/quicktime',
		'mov' => 'video/quicktime',

		// adobe
		'pdf' => 'application/pdf',
		'psd' => 'image/vnd.adobe.photoshop',
		'ai'  => 'application/postscript',
		'eps' => 'application/postscript',
		'ps'  => 'application/postscript',

		// ms office
		'doc' => 'application/msword',
		'rtf' => 'application/rtf',
		'xls' => 'application/vnd.ms-excel',
		'ppt' => 'application/vnd.ms-powerpoint',

		// open office
		'odt' => 'application/vnd.oasis.opendocument.text',
		'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
	);

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		if (!isset($this->attrs->server['FR_URL'])) {
			$this->status(404);
			$this->finish();
			return;
		}

		try {
			$this->stream = new AsyncStream($this->attrs->server['FR_URL']);

			if ($this->stream->fileMode) {
				if (
					substr($this->stream->filePath, -1) === '/' 
					&& is_dir($this->stream->filePath)
				) {
					$found = FALSE;

					foreach ($this->appInstance->indexFiles as $i) {
						if (is_file($this->stream->filePath . $i)) {
							$this->stream = new AsyncStream('file://' . $this->stream->filePath . $i);
							$found = TRUE;
							break;
						}
					}

					if (!$found) {
						if (
							isset($this->attrs->server['FR_AUTOINDEX']) 
							&& $this->attrs->server['FR_AUTOINDEX']
						) {
							$h = opendir($this->stream->filePath);

							if (!$h) {
								$this->status(404);
								$this->finish();
								return;
							}

							?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd"> 
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en"> 
<head> 
<title>Index of /</title> 
<style type="text/css"> 
a, a:active {text-decoration: none; color: blue;}
a:visited {color: #48468F;}
a:hover, a:focus {text-decoration: underline; color: red;}
body {background-color: #F5F5F5;}
h2 {margin-bottom: 12px;}
table {margin-left: 12px;}
th, td { font: 90% monospace; text-align: left;}
th { font-weight: bold; padding-right: 14px; padding-bottom: 3px;}
td {padding-right: 14px;}
td.s, th.s {text-align: right;}
div.list { background-color: white; border-top: 1px solid #646464; border-bottom: 1px solid #646464; padding-top: 10px; padding-bottom: 14px;}
div.foot { font: 90% monospace; color: #787878; padding-top: 4px;}
</style> 
</head> 
<body> 
<pre class="header"Welcome!</pre><h2>Index of /</h2> 
<div class="list"> 
<table summary="Directory Listing" cellpadding="0" cellspacing="0"> 
<thead><tr><th class="n">Name</th><th class="m">Last Modified</th><th class="s">Size</th><th class="t">Type</th></tr></thead> 
<tbody> 
<tr><td class="n"><a href="../">Parent Directory</a>/</td><td class="m">&nbsp;</td><td class="s">- &nbsp;</td><td class="t">Directory</td></tr> 
<?php

while (($fn = readdir($h)) !== FALSE) {
	if (
		($fn === '.') 
		|| ($fn === '..')
	) {
		continue;
	}

	$path = $this->stream->filePath . $fn;
	$type = is_dir($path) ? 'Directory' : self::getMIME($path);

	?>
<tr><td class="n"><a href="<?php echo htmlspecialchars($fn) . ($type == 'Directory' ? '/' : ''); ?>"><?php echo htmlspecialchars($fn); ?></a></td><td class="m"><?php echo date('Y-M-D H:i:s', filemtime($path)); ?></td><td class="s"><?php echo ($type === 'Directory' ? '-' : $this->humanSize(filesize($path))); ?> &nbsp;</td><td class="t"><?php echo $type; ?></td></tr>
	<?php
}

?>
</tbody> 
</table> 
</div> 
<?php 

if ($this->upstream->config->expose->value) {
	echo '<div class="foot">phpDaemon/' . Daemon::$version . '</div>';
} 

?> 
</body> 
</html>
<?php

							$this->finish();
							return;
						} else {
							$this->status(403);
							$this->finish();
							return;
						}
					}
				}
				elseif (is_dir($this->stream->filePath)) {
					$this->header('Location: ' . $this->attrs->server['FR_URL'] . '/');
					$this->finish();
					return;
				}

				$this->header('Content-Type: ' . self::getMIME($this->stream->filePath));
			}

			$this->stream->
				onReadData($this->stream->fileMode ?
					function($stream, $data) {
						$stream->request->out($data);
					} :
					function($stream, $data) {
						$stream->request->combinedOut($data);
					}
				)->
				onEOF(
					function($stream) {
						$stream->request->finish();
					}
				)->
				setRequest($this)->
				enable();
		} catch (BadStreamDescriptorException $e) {
			$this->status(404);
			$this->finish();
		} catch (Exception $e) {
			$this->status(404);
			$this->finish();
		}
	}

	/**
	 * @method onAbort
	 * @description Called when the request aborted.
	 * @return void
	 */
	public function onAbort() {
		$this->finish();
	}

	/**
	 * @method onFinish
	 * @description Called when the request finished.
	 * @return void
	 */
	public function onFinish() {
		if ($this->stream) {
			$this->stream->close();
		}
	}

	/**
	 * @method run
	 * @description Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if (!$this->stream->eof()) {
			$this->sleep();
		}
	}
	
	/**
	 * @method humanSize
	 * @description Returns human-readable size.
	 * @return void
	 */
	private function humanSize($size) {
		if ($size >= 1073741824) {
			$size = round($size / 1073741824, 2) . 'G';
		}
		elseif ($size >= 1048576) {
			$size = round($size / 1048576, 2) .'M';
		}
		elseif ($size >= 1024) {
			$size = round($size / 1024 * 100, 2) .'K';
		} else {
			$size = $size . 'B';
		}

		return $size;
	}
	
	/**
	 * @method getMIME()
	 * @param string - Path
	 * @description Returns MIME type of the given file.
	 * @return string - MIME type.
	 */
	public static function getMIME($path) {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (isset(self::$fileTypes[$ext])) {
			return self::$fileTypes[$ext];
		}
		elseif (function_exists('finfo_open')) {
			if (!is_readable($path)) {
				return 'application/octet-stream';
			}

			$finfo = finfo_open(FILEINFO_MIME);
			$mimetype = finfo_file($finfo, $path);

			finfo_close($finfo);

			return $mimetype;
		} else {
			return 'application/octet-stream';
		}
	}
	
}
