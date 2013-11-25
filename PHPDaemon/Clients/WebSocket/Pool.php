<?php
namespace PHPDaemon\Clients\WebSocket;

use PHPDaemon\Network;
use PHPDaemon\Config\Entry;

/**
 * Class Pool
 * @package networkClients
 * @subpackages WebSocketClient
 *
 * @author Kozin Denis <kozin.alizarin.denis@gmail.com>
 */
class Pool extends Network\Client {

	/** Types of WebSocket frame */
	const TYPE_TEXT = 'text';
	const TYPE_BINARY = 'binary';
	const TYPE_CLOSE = 'close';
	const TYPE_PING = 'ping';
	const TYPE_PONG = 'pong';

	public function getConfigDefaults() {
		return [
			/**
			 * Maximum allowed size of packet
			 * @var integer
			 */
			'max-allowed-packet' => new Entry\Size('1M'),
		];
	}
}
