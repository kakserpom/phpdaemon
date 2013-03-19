<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleBroadcastCall extends AppInstance {
	
	public $enableRPC = true;
	
	public function hello($pid) {
	
		Daemon::$process->log('I got hello from '.$pid.'!');
	
	}
	
	public function onReady() {
		 
		$appInstance = $this;
		
		setTimeout(function($event) use ($appInstance) {
			
			$appInstance->broadcastCall('hello', array(Daemon::$process->getPid()));

			$event->finish();
			
		}, 2e6);
		
	}
}