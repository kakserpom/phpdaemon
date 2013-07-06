<?php
namespace PHPDaemon\IPCManager;

/**
 * @package    Applications
 * @subpackage IPCManager
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class MasterPool extends \PHPDaemon\Network\Server {
	/** @var array */
	public $workers = [];
	/** @var string */
	public $connectionClass = '\PHPDaemon\IPCManager\MasterPoolConnection';
}