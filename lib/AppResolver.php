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
			if (isset($section->limitinstances)) {
				continue;
			}
			if (
					(isset($section->enable) && $section->enable->value)
					||
					(!isset($section->enable) && !isset($section->disable))
			) {
				if (strpos($fullname,':') === false) {
					$fullname .= ':';
				}
				list($appName, $instance) = explode(':', $fullname, 2);
				$appNameLower = strtolower($appName);
				if ($appNameLower !== 'pool' && $privileged && (!isset($section->privileged) || !$section->privileged->value)) {
					continue;
				}
				if (isset(Daemon::$appInstances[$appNameLower][$instance])) {
					continue;
				}
				$this->appInstantiate($appName, $instance, true);
			}
		}
	}

	/**
	 * Gets instance of application by it's name.
	 * @param string Application name.	 
	 * @return object AppInstance.
	 */
	public function getInstanceByAppName($appName, $instance = '', $spawn = true) {
		$appNameLower = strtolower($appName);
		if (!isset(Daemon::$appInstances[$appNameLower][$instance])) {
			if (!$spawn) {
				return false;
			}
			return $this->appInstantiate($appName, $instance);
		}
		return Daemon::$appInstances[$appNameLower][$instance];
	}

	/**
	 * Check if instance of application was enabled during preload.
	 * @param string Application name.	 
	 * @return bool
	 */
	public function checkAppEnabled($appName, $instance = '') {
		$appNameLower = strtolower($appName);
		if (!isset(Daemon::$appInstances[$appNameLower])) {
			return false;
		}

		if (!empty($instance) && !isset(Daemon::$appInstances[$appNameLower][$instance])) {
			return false;
		}

		$fullname = $this->getAppFullname($appName, $instance);

		return !isset(Daemon::$config->{$fullname}->enable) ? false : !!Daemon::$config->{$fullname}->enable->value;
	}

	/**
	 * Resolve full name of application by its class and name
	 * @param string Application class.	 
	 * @param string Application name.
	 * @return string 
	 */
	public function getAppFullname($appName, $instance = '') {
		return $appName . ($instance !== '' ? ':' . $instance : '');
	}

	/**
	 * Run new application instance	
	 * @param string Application name
	 * @param string Name of instance
	 * @param [boolean = false] Preload?
	 * @return object AppInstance.
	 */
	public function appInstantiate($appName, $instance, $preload = false) {
		$fullname = $this->getAppFullname($appName, $instance);
		if (!class_exists($appName) || !is_subclass_of($appName, 'AppInstance')) {
			return false;
		}
		if (!$preload) {
			if (!$appName::$runOnDemand) {
				return false;
			}
			if (isset(Daemon::$config->{$fullname}->limitinstances)) {
				return false;
			}
		}
		return new $appName($instance);
	}

	/**
	 * Routes incoming request to related application
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @param string Default application name.
	 * @return object Request.
	 */
	public function getRequest($req, $upstream, $defaultApp = null) {
		if (isset($req->attrs->server['APPNAME'])) {
			$appName = $req->attrs->server['APPNAME'];
		}
		elseif (($appName = $this->getRequestRoute($req, $upstream)) !== null) {
			if ($appName === false) {
				return $req;
			}
		}
		else {
			$appName = $defaultApp;
		}
		if (strpos($appName,':') === false) {
			$appName .= ':';
		}
		list($app, $instance) = explode(':', $appName, 2);

		$appInstance = $this->getInstanceByAppName($app, $instance);

		if (!$appInstance) {
			return $req;
		}

		return $appInstance->handleRequest($req, $upstream);
	}

	/**
	 * Routes incoming request to related application. Method is for overloading.	
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) { }
	
}
