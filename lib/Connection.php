<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Connection extends IOStream {
	public function connectTo($host, $port = 0) {
		if (stripos($host, 'unix:') === 0) {
			// Unix-socket
			$e = explode(':', $host, 2);
			$this->addr = $host;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_UNIX, SOCK_STREAM, 0);

				if (!$fd) {
					return FALSE;
				}
				socket_set_nonblock($fd);
				@socket_connect($fd, $e[1], 0);
			} else {
				$fd = @stream_socket_client('unix://' . $e[1]);

				if (!$fd) {
					return FALSE;
				}
				stream_set_blocking($fd, 0);
			}
		} 
		elseif (stripos($host, 'raw:') === 0) {
			// Raw-socket
			$e = explode(':', $host, 2);
			$this->addr = $host;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_INET, SOCK_RAW, 1);
				if (!$fd) {
					return false;
				}
				socket_set_nonblock($fd);
				@socket_connect($fd, $e[1], 0);
			} else {
				return false;
			}
		} else {
			// TCP
			$this->addr = $host . ':' . $port;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				if (!$fd) {
					return FALSE;
				}
				socket_set_nonblock($fd);
				@socket_connect($fd, $host, $port);
			} else {
				$fd = @stream_socket_client(($host === '') ? '' : $host . ':' . $port);
				if (!$fd) {
					return FALSE;
				}
				stream_set_blocking($fd, 0);
			}
		}
		$this->setFd($fd);
		return true;
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (!isset($this->buffer)) {
			return false;
		}
		
		if (isset($this->readEvent)) {
			if (Daemon::$useSockets) {
				$read = socket_read($this->fd, $n);

				if ($read === false) {
					$no = socket_last_error($this->fd);

					if ($no !== 11) {  // Resource temporarily unavailable
						Daemon::log(get_class($this) . '::' . __METHOD__ . ': id = ' . $this->id . '. Socket error. (' . $no . '): ' . socket_strerror($no));
						$this->onFailureEvent($this->id);
					}
				}
			} else {
				$read = fread($this->fd, $n);
			}
		} else {
			$read = event_buffer_read($this->buffer, $n);
		}
		if (
			($read === '') 
			|| ($read === null) 
			|| ($read === false)
		) {
			$this->reading = false;
			return false;
		}
		return $read;
	}
	
	public function closeFd() {
		if (Daemon::$useSockets) {
			socket_close($this->fd);
		} else {
			fclose($this->fd);
		}
	}
}
