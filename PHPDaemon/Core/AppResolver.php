<?php
namespace PHPDaemon\Core;

use PHPDaemon\Config;
use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\ClassFinder;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Request\Generic;

/**
 * Application resolver
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class AppResolver {

	/*
	 * Preloads applications.
	 * @param boolean Privileged.
	 * @return void
	 */
	public function preload($privileged = false) {

		foreach (Daemon::$config as $fullname => $section) {

			if (!$section instanceof Config\Section) {
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
				if (strpos($fullname, ':') === false) {
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
				$this->getInstance($appName, $instance, true, true);
			}
		}
	}

	/**
	 * Gets instance of application
	 * @param string Application name.
	 * @return object AppInstance.
	 */
	public function getInstanceByAppName($appName, $instance = '', $spawn = true, $preload = false) {
		return $this->getInstance($appName, $instance, $spawn, $preload);
	}

	/**
	 * Gets instance of application
	 * @param string Application name.
	 * @return object AppInstance.
	 */
	public function getInstance($appName, $instance = '', $spawn = true, $preload = false) {
		$class = ClassFinder::find($appName, 'Applications');
		if (isset(Daemon::$appInstances[$class][$instance])) {
			return Daemon::$appInstances[$class][$instance];
		}
		if (!$spawn) {
			return false;
		}
		$fullname = $this->getAppFullname($appName, $instance);
		if (!class_exists($class) || !is_subclass_of($class, '\\PHPDaemon\\Core\\AppInstance')) {
			Daemon::$process->log(__METHOD__.': cannot find application class \''.$class.'\'');
			return false;
		}
		$fullnameClass = $this->getAppFullname($class, $instance);
		if ($fullname !== $fullnameClass && isset(Daemon::$config->{$fullname})) {
			Daemon::$config->{$fullnameClass} = Daemon::$config->{$fullname};
			unset(Daemon::$config->{$fullname});
		}
		if (!$preload) {
			if (!$class::$runOnDemand) {
				return false;
			}
			if (isset(Daemon::$config->{$fullnameClass}->limitinstances)) {
				return false;
			}
		}
		return new $class($instance);
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
	 * Routes incoming request to related application
	 * @param object Generic.
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
		if (strpos($appName, ':') === false) {
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
	 * @param object Generic.
	 * @param object AppInstance of Upstream.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) { }

}
