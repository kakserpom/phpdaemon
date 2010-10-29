<?php

/**
 * Application resolver
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AppResolver {

	/*
	 * Preloads applications.
	 * @param boolean Privileged.
	 * @return void
	 */
	public function preload($privileged = false) {
	
		foreach (Daemon::$config as $fullname => $section) {
		
			if (!$section instanceof Daemon_ConfigSection)	{
				continue;
			}
			if (isset($section->limitinstances)) {continue;}
			if (
					(isset($section->enable) && $section->enable->value)
					||
					(!isset($section->enable) && !isset($section->disable))
			) {
				if ($privileged && (!isset($section->privileged) || !$section->privileged->value)) {
					continue;
				}
				if (strpos($fullname,'-') === false) {
					$fullname .= '-';
				}
				list($app, $name) = explode('-', $fullname, 2);
				if (isset(Daemon::$appInstances[$app][$name])) {
					continue;
				}
				$this->appInstantiate($app,$name);
			}
		}
	}

	/**
	 * Gets instance of application by it's name.
	 * @param string Application name.	 
	 * @return object AppInstance.
	 */
	public function getInstanceByAppName($appName,$name =	'') {
		if (!isset(Daemon::$appInstances[$appName][$name])) {
			return $this->appInstantiate($appName,$name);
		}

		return Daemon::$appInstances[$appName][$name !== ''?$name : array_rand(Daemon::$appInstances[$appName])];
	}

	/**
	 * Gets path to application's PHP-file.	
	 * @param string Application name
	 * @param string Instance name
	 * @return string Path.
	 */
	public function getAppPath($app,$name) {
		$fn = $app.($name !== ''?'-'.$name:'');
		if (isset(Daemon::$config->{$fn}->path->value)) {return Daemon::$config->{$fn}->path->value;}
		$files = glob(sprintf(Daemon::$config->appfilepath->value,$app), GLOB_BRACE);
		return isset($files[0]) ? $files[0] : false;
 	}

	/**
	 * Run new application instance	
	 * @param string Application name
	 * @param string Name of instance
	 * @return object AppInstance.
	 */
	public function appInstantiate($app,$name) {
		if (!isset(Daemon::$appInstances[$app])) {
			Daemon::$appInstances[$app] = array();
		}

		if (class_exists($app)) {
			$appInstance = new $app($name);
		} else {
			$p = $this->getAppPath($app,$name);

			if (
				!$p 
				|| !is_file($p)
			) {
				Daemon::log('appInstantiate(' . $app . ') failed: application doesn\'t exist'.($p?' ('.$p.')':'').'.');
				return FALSE;
			}

			$appInstance = include $p;
		}

		if (
			!is_object($appInstance)
			&& class_exists($app)
		) {
			$appInstance = new $app($name);
		}

		if (!is_object($appInstance)) {
			Daemon::log('appInstantiate(' . $app . ') failed. Class not exists.');
			return FALSE;
		}

		Daemon::$appInstances[$app][$name] = $appInstance;

		return $appInstance;
	}

	/**
	 * Routes incoming request to related application
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @param string Default application name.
	 * @return object Request.
	 */
	public function getRequest($req, $upstream, $defaultApp = NULL) {
		if (isset($req->attrs->server['APPNAME'])) {
			$appName = $req->attrs->server['APPNAME'];
		}
		elseif (($appName = $this->getRequestRoute($req, $upstream)) !== NULL) {}
		else {
			$appName = $defaultApp;
		}

		$appInstance = $this->getInstanceByAppName($appName);

		if (!$appInstance) {
			return $req;
		}

		return $appInstance->handleRequest($req,$upstream);
	}

	/**
	 * Routes incoming request to related application. Method is for overloading.	
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) { }
	
}
