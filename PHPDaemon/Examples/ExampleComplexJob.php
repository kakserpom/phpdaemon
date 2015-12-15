<?php
namespace PHPDaemon\Examples;

/*
 * All listeners will be called after async calling "foo", "bar", "baz" jobs.
 *
 * @package Examples
 * @subpackage Base
 *
 * @author Vasily Zorin <maintainer@daemon.io>
 */
class ExampleComplexJob extends \PHPDaemon\Core\AppInstance {

	/**
	 * Called when the worker is ready to go
	 *
	 * @return void
	 */
	public function onReady() {

		// Adding listener
		// ComplexJob - STATE_WAITING
		$job = new \PHPDaemon\Core\ComplexJob(function ($job) {
			// ComplexJob - STATE_DONE
			/*array (
			  'bar' =>
			  array (
					'job' => 'bar',
					'success' => false,
					'line' => 63,
			  ),
			  'foo' =>
			  array (
					'job' => 'foo',
					'success' => true,
					'line' => 84,
					'arg' =>
					array (
					  'param' => 'value',
					),
			  ),
			  'baz' =>
			  array (
					'job' => 'baz',
					'success' => false,
					'line' => 94,
			  ),
			)*/
			\PHPDaemon\Core\Daemon::log($job->results);
		});

		// Adding listener
		// ComplexJob - STATE_WAITING
		$job->addListener(function ($job) {
			// ComplexJob - STATE_DONE
		});

		// Adding async job foo
		$job('foo', $this->foo(['param' => 'value']));

		// Adding with 1 sec delay
		\PHPDaemon\Core\Timer::add(function ($event) use ($job) {

			// Adding async job bar
			$job('bar', function ($jobname, $job) {
				\PHPDaemon\Core\Timer::add(function ($event) use ($jobname, $job) {
					// Job done
					$job->setResult($jobname, ['job' => 'bar', 'success' => false, 'line' => __LINE__]);
					$event->finish();
				}, 1e3 * 50);
			});

			// Adding async job baz. Equal $job('baz', $this->baz());
			$job->addJob('baz', $this->baz());

			// Run jobs. All listeners will be called when the jobs done
			// ComplexJob - STATE_RUNNING
			$job();

			$event->finish();
		}, 1e6 * 1);

	}

	final public function foo($arg) {
		return function ($jobname, $job) use ($arg) {
			\PHPDaemon\Core\Timer::add(function ($event) use ($jobname, $job, $arg) {
				// Job done
				$job->setResult($jobname, ['job' => 'foo', 'success' => true, 'line' => __LINE__, 'arg' => $arg]);
				$event->finish();
			}, 1e3 * 100);
		};
	}

	final public function baz() {
		return function ($jobname, $job) {
			\PHPDaemon\Core\Timer::add(function ($event) use ($jobname, $job) {
				// Job done
				$job->setResult($jobname, ['job' => 'baz', 'success' => false, 'line' => __LINE__]);
				$event->finish();
			}, 1e3 * 300);
		};
	}
}
