<?php

/**
 * @package Applications
 * @subpackage FileReader
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FileReader extends AppInstance {

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// index file names
			'indexfiles'	=> 'index.html/index.htm'
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->onConfigUpdated();
	}
	
	public function onConfigUpdated() {
		$this->indexFiles = explode('/', $this->config->indexfiles->value);
	}

	/**
	 * Creates Request.
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
	public $job;
	public $indexFile;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if (!isset($this->attrs->server['FR_PATH'])) {
			$this->status(404);
			$this->finish();
			return;
		}
		$job = new ComplexJob(function($job) {
			$this->wakeup();
		});
		$this->job = $job;
		$this->sleep(5, true);
		$this->attrs->server['FR_PATH'] = FS::sanitizePath($this->attrs->server['FR_PATH']);
		$job('stat', function($name, $job) {
			FS::stat($this->attrs->server['FR_PATH'], function($path, $stat) use ($job) {
				if ($stat === -1) {
					$this->fileNotFound();
					$job->setResult('stat', false);
					return;
				}
				if ($stat['type'] === 'd') {
					if (!FS::$supported) {
						$this->file(rtrim($path,'/') . '/index.html');
					}
					else $job('readdir', function ($name, $job) use ($path) {
						FS::readdir(rtrim($path,'/'), function ($path, $dir) use ($job) {
							$found = false;
							if (is_array($dir)) {
								foreach ($dir['dents'] as $file) {
									if ($file['type'] === EIO_DT_REG) { // is file
										if (in_array($file['name'], $this->appInstance->indexFiles)) {
											$this->file($path . '/' . $file['name']);
											$found = true;
											break;
										}
									}									
								}
							}
							if (!$found) {
								if (isset($this->attrs->server['FR_AUTOINDEX']) && $this->attrs->server['FR_AUTOINDEX']) {
									$this->autoindex($path, $dir);
								} else {
									$this->fileNotFound();
								}
							}
							$job->setResult('readdir');
						},  EIO_READDIR_STAT_ORDER | EIO_READDIR_DENTS);
					});
				} elseif ($stat['type'] == 'f') {
					$this->file($path);
				}
				$job->setResult('stat', $stat);
			});
		});
		$job();	
	}
	public function fileNotFound() {
		try {
			$this->header('404 Not Found');
			$this->header('Content-Type: text/html');
		} catch (RequestHeadersAlreadySent $e ) {}
		$this->out('File not found.');
	}
	public function file($path) {
		if (!FS::$supported) {
			$this->out(file_get_contents(realpath($path)));
			$this->wakeup();
			return;
		}
		static $warn = false;
		if ($warn) {
			Daemon::log('BEWARE! You are using FileReader with EIO enabled. Maybe this is still buggy.');
			$warn = true;
		}
		$job = $this->job;
		$job('readfile', function ($name, $job) use ($path) {
			$this->sendfile($path, function($file, $success) use ($job, $name) {
				$job->setResult($name);
			});
		});
	}
	public function autoindex($path, $dir) {
		$this->onWakeup();

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd"> 
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
<pre class="header">Welcome!</pre><h2>Index of /</h2> 
<div class="list"> 
<table summary="Directory Listing" cellpadding="0" cellspacing="0"> 
<thead>
<tr>
	<th class="n">Name</th>
	<th class="t">Type</th>
</tr>
</thead> 
<tbody> 
<tr>
	<td class="n"><a href="../">Parent Directory</a>/</td>
	<td class="t">Directory</td>
</tr> 
<?php
	foreach ($dir['dents'] as $item) {
		$type = $item['type'] === EIO_DT_DIR ? 'Directory' : MIME::get($path . $item['name']);
	?><tr>
			<td class="n"><a href="<?php echo htmlspecialchars($item['name']) . ($type == 'Directory' ? '/' : ''); ?>"><?php echo htmlspecialchars($item['name']); ?></a></td>
			<td class="t"><?php echo $type; ?></td>
		</tr>
		<?php } ?>
</tbody> 
</table> 
</div> <?php if ($this->upstream->pool->config->expose->value) {?><div class="foot">phpDaemon/<?php echo Daemon::$version; ?></div><?php } ?>
</body> 
</html><?php
	}
	/**
	 * Called when the request aborted.
	 * @return void
	 */
	public function onAbort() {
		$this->finish();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {}	
}
