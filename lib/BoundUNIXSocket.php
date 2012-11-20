<?php

/**
 * BoundUNIXSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundUNIXSocket extends BoundSocket {

	/**
	 * Bind socket
	 * @return boolean Success.
	 */
	 public function bind() {
		$e = explode(':', $this->addr, 4);
		if (sizeof($e) == 3) {
			$user = $e[0];
			$group = $e[1];
			$path = $e[2];
		}
		elseif (sizeof($e) == 2) {
			$user = $e[0];
			$group = FALSE;
			$path = $e[1];
		} else {
			$user = FALSE;
			$group = FALSE;
			$path = $e[0];
		}

		if (pathinfo($path, PATHINFO_EXTENSION) !== 'sock') {
			Daemon::$process->log('Unix-socket \'' . $path . '\' must has \'.sock\' extension.');
			return;
		}
				
		if (file_exists($path)) {
			unlink($path);
		}

		$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t create UNIX-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}

		// SO_REUSEADDR is meaningless in AF_UNIX context
		if (!@socket_bind($sock, $path)) {
			if (isset($this->config->maxboundsockets->value)) { // no error-messages when maxboundsockets defined
				return false;
			}
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return;
		}
		if (!socket_listen($sock, SOMAXCONN)) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t listen UNIX-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
		}
		socket_set_nonblock($sock);
		chmod($path, 0770);
		if (
			($group === FALSE) 
			&& isset(Daemon::$config->group->value)
		) {
			$group = Daemon::$config->group->value;
		}
		if ($group !== FALSE) {
			if (!@chgrp($path, $group)) {
				unlink($path);
				Daemon::log('Couldn\'t change group of the socket \'' . $path . '\' to \'' . $group . '\'.');
				return false;
			}
		}
		if (
			($user === FALSE) 
			&& isset(Daemon::$config->user->value)
		) {
			$user = Daemon::$config->user->value;
		}
		if ($user !== FALSE) {
			if (!@chown($path, $user)) {
				unlink($path);
				Daemon::log('Couldn\'t change owner of the socket \'' . $path . '\' to \'' . $user . '\'.');
				return false;
			}
		}
		$this->setFd($sock);
		return true;	
	}
}
