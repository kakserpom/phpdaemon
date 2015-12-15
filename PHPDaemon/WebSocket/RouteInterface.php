<?php
namespace PHPDaemon\WebSocket;

/**
 * Web socket route
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
interface RouteInterface {
	// @TODO: fill
	
	/**
	 * Called when new frame is received
	 * @param string $data Frame's contents
	 * @param integer $type Frame's type
	 * @return void
	 */
	public function onFrame($data, $type);
}
