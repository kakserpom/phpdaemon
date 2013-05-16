<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExampleBroadcastCall extends \PHPDaemon\Core\AppInstance {

	public $enableRPC = true;

	public function hello($pid) {

		\PHPDaemon\Core\Daemon::$process->log('I got hello from ' . $pid . '!');

	}

	public function onReady() {

		$appInstance = $this;

		setTimeout(function ($event) use ($appInstance) {

			$appInstance->broadcastCall('hello', array(\PHPDaemon\Core\Daemon::$process->getPid()));

			$event->finish();

		}, 2e6);

	}
}