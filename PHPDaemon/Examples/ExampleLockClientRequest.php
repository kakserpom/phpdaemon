<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExampleLockClientRequest extends Generic {
	public $started = false;

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {

		if (!$this->started) {
			$this->started = true;
			$LockClient    = \PHPDaemon\Daemon::$appResolver->getInstanceByAppName('LockClient');
			$req           = $this;
			$LockClient->job(
				'ExampleJobName', // name of the job
				false, //wait?
				function ($command, $jobname, $client) use ($req) {
					if ($command === 'RUN') {
						\PHPDaemon\Timer::add(function ($event) use ($req, $jobname, $client) {
							\PHPDaemon\Daemon::log('done');
							$client->done($jobname);
							$req->out(':-)');
							$req->wakeup();
							$event->finish();
						}, pow(10, 6) * 1);
					}
					else {
						$req->out(':-(');
						$req->wakeup();
					}
				}
			);
			$this->sleep(5); //timeout
		}
	}
}
