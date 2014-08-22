<?php
namespace PHPDaemon\Applications;

/**
 * Class CGI
 * @package PHPDaemon\Applications
 */
class CGI extends \PHPDaemon\Core\AppInstance {

	/**
	 * @var string
	 */
	public $binPath = 'php-cgi'; // Default bin-path
	/**
	 * @var array
	 */
	public $binAliases = [
		'php5'   => '/usr/local/php/bin/php-cgi',
		'php6'   => '/usr/local/php6/bin/php-cgi',
		'perl'   => '/usr/bin/perl',
		'python' => '/usr/local/bin/python',
		'ruby'   => '/usr/local/bin/ruby',
	];

	/**
	 * @var string
	 */
	public $chroot = '/'; // default chroot

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'allow-override-binpath' => true,
			'allow-override-cwd'     => true,
			'allow-override-chroot'  => true,
			'allow-override-user'    => true,
			'allow-override-group'   => true,
			'cwd'                    => null,
			'output-errors'          => true,
			'errlog-file'            => __DIR__ . '/cgi-error.log',
		];
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {

	}

	/**
	 * Creates Request.
	 * @param object $req      Request.
	 * @param object $upstream Upstream application instance.
	 * @return CGIRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new CGIRequest($this, $upstream, $req);
	}
}
