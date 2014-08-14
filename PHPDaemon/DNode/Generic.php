<?php
namespace PHPDaemon\DNode;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Generic
 *
 * @package DNode
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
abstract class Generic extends \PHPDaemon\WebSocket\Route {
	use \PHPDaemon\WebSocket\Traits\DNode;

	// @DEPRECATED
	// @TODO: Remove this class in future versions
}
