<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleLockClient extends AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleLockClientRequest($this, $upstream, $req);
	}
}

class ExampleLockClientRequest extends HTTPRequest {
	public $started = false;
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
	
		if (!$this->started) {
			$this->started = true;
			$LockClient = Daemon::$appResolver->getInstanceByAppName('LockClient');
			$req = $this;
			$LockClient->job(
					'ExampleJobName', // name of the job
					false, //wait?
					function($command,$jobname,$client) use ($req) {
						if ($command === 'RUN') {
							Timer::add(function($event) use ($req, $jobname, $client) {
							Daemon::log('done');
								$client->done($jobname);
								$req->out(':-)');
								$req->wakeup();
								$event->finish();
							}, pow(10,6) * 1);
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
