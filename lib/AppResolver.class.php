<?php

class AppResolver {
	/*
	 * @method preloadPrivileged
	 * @description Preloads applications.
	 * @param boolean Privileged.
	 * @return void
	 */
	public function preload($privileged = false) {
	
		foreach (Daemon::$config as $fullname => $section) {
		
			if (!$section instanceof Daemon_ConfigSection)	{
				continue;
			}
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
	 * @method getInstanceByAppName
	 * @param string Application name.
	 * @description Gets instance of application by it's name.
	 * @return object AppInstance.
	 */
	public function getInstanceByAppName($appName,$name =	'') {
		if (!isset(Daemon::$appInstances[$appName][$name])) {
			return $this->appInstantiate($appName,$name);
		}

		return Daemon::$appInstances[$appName][$name !== NULL?$name : array_rand(Daemon::$appInstances[$appName])];
	}

	/**
	 * @method getAppPath
	 * @param string Application name
	 * @description Gets path to application's PHP-file.
	 * @return string Path.
	 */
	public function getAppPath($app) {
		$files = glob(sprintf(Daemon::$config->appfilepath->value,$app), GLOB_BRACE);
		return isset($files[0]) ? $files[0] : false;
 	}

	/**
	 * @method appInstantiate
	 * @param string Application name
	 * @param string Name of instance
	 * @description Run new application instance.
	 * @return object AppInstance.
	 */
	public function appInstantiate($app,$name) {
		if (!isset(Daemon::$appInstances[$app])) {
			Daemon::$appInstances[$app] = array();
		}

		if (class_exists($app)) {
			$appInstance = new $app($name);
		}
		else {
			$p = $this->getAppPath($app);

			if (
				!$p 
				|| !is_file($p)
			) {
				Daemon::log('appInstantiate(' . $app . ') failed: application doesn\'t exist.');
				return FALSE;
			}

			$appInstance = include $p;
		}
		if (!is_object($appInstance)) {
			if (class_exists($app)) {
				$appInstance = new $app($name);
			}
		}
		if (!is_object($appInstance)) {
					Daemon::log('appInstantiate(' . $app . ') failed. Class not exists.');
					return FALSE;
		}
	
		Daemon::$appInstances[$app][$name] = $appInstance;

		return $appInstance;
	}

	/**
	 * @method getRequest
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @param string Default application name.
	 * @description Routes incoming request to related application.
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
	 * @method getRequestRoute
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @description Routes incoming request to related application. Method is for overloading.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) {}
}
