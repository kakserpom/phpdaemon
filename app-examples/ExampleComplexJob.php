<?php
/*
 * All listeners will be called after async calling "foo", "bar", "baz" jobs.
 *
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleComplexJob extends AppInstance {

	/**
	 * Called when the worker is ready to go
	 * 
	 * @return void
	 */
    public function onReady() {

        // Adding listener
        // ComplexJob - STATE_WAITING
        $job = new ComplexJob(function($job) {
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
            Daemon::log($job->results);
        });

        // Adding listener
        // ComplexJob - STATE_WAITING
        $job->addListener(function($job) {
			// ComplexJob - STATE_DONE
        });

        // Incapsulate some property in job
        $job->appInstance = $this;

        // Adding async job foo
        $job('foo', $this->foo(array('param' => 'value')));

        // Adding with 1 sec delay
        Timer::add(function($event) use ($job) {

            // Adding async job bar
            $job('bar', function($jobname, $job) {
                Timer::add(function($event) use($jobname, $job) {
                    // Job done
                    $job->setResult($jobname, array('job' => 'bar', 'success' => false, 'line' => __LINE__));
                    $event->finish();
                }, 1e3 * 50);
            });

            // Adding async job baz. Equal $job('baz', $job->appInstance->baz());
            $job->addJob('baz', $job->appInstance->baz());

            // Run jobs. All listeners will be called when the jobs done
            // ComplexJob - STATE_RUNNING
            $job();
                
            $event->finish();
        }, 1e6 * 1);

    }

    final public function foo($arg) {
        return function($jobname, $job) use ($arg) {
            Timer::add(function($event) use($jobname, $job, $arg) {
                // Job done
                $job->setResult($jobname, array('job' => 'foo', 'success' => true, 'line' => __LINE__, 'arg' => $arg));
                $event->finish();
            }, 1e3 * 100);
        };
    }

    final public function baz() {
        return function($jobname, $job) {
            Timer::add(function($event) use($jobname, $job) {
                // Job done
                $job->setResult($jobname, array('job' => 'baz', 'success' => false, 'line' => __LINE__));
                $event->finish();
            }, 1e3 * 300);
        };
    }
}
