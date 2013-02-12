<?php

/**
 * @package Applications
 * @subpackage Asterisk
 *
 * @author TyShkan <denis@tyshkan.ru>
 */
class Asterisk extends AppInstance {
	
	public $asterisk;
	
	public function onReady() {
		$this->asterisk = AsteriskClient::getInstance();
		
		$session = $this->asterisk->getConnection();
	}
	
}