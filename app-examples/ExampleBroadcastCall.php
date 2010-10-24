<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleBroadcastCall extends AppInstance {
	
	public $enableRPC = true;
	
	public function hello($arg0) {
	
		$this->log('Hello '.$arg0.'!');
	
	}
	
	public function onReady() {
		
		$this->broadcastCall('hello',array('world'));
		
	}
}