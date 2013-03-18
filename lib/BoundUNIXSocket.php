<?php

/**
 * BoundUNIXSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundUNIXSocket extends BoundSocket {
	protected $group;
	protected $user;

	/**
	 * Bind socket
	 * @return boolean Success.
	 */
	 public function bindSocket() {
	 	if ($this->errorneous) {
	 		return false;
	 	}

		if (pathinfo($this->uri['path'], PATHINFO_EXTENSION) !== 'sock') {
			Daemon::$process->log('Unix-socket \'' . $this->uri['path'] . '\' must has \'.sock\' extension.');
			return;
		}

		if (file_exists($this->uri['path'])) {
			unlink($this->uri['path']);
		}

		$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t create UNIX-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}

		// SO_REUSEADDR is meaningless in AF_UNIX context
		if (!@socket_bind($sock, $this->uri['path'])) {
			if (isset($this->config->maxboundsockets->value)) { // no error-messages when maxboundsockets defined
				return false;
			}
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $this->uri['path'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return;
		}
		if (!socket_listen($sock, SOMAXCONN)) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t listen UNIX-socket \'' . $this->uri['path'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
		}
		socket_set_nonblock($sock);
		chmod($this->uri['path'], 0770);
		if ($this->group === null && !empty($this->uri['pass'])) {
			$this->group = $this->uri['pass'];
		}
		if ($this->group === null && isset(Daemon::$config->group->value)) {
			$this->group = Daemon::$config->group->value;
		}
		if ($this->group !== null) {
			if (!@chgrp($this->uri['path'], $this->group)) {
				unlink($this->uri['path']);
				Daemon::log('Couldn\'t change group of the socket \'' . $this->uri['path'] . '\' to \'' . $this->group . '\'.');
				return false;
			}
		}
		if ($this->user === null && !empty($this->uri['user'])) {
			$this->user = $this->uri['user'];
		}
		if ($this->user === null && isset(Daemon::$config->user->value)) {
			$this->user = Daemon::$config->user->value;
		}
		if ($this->user !== null) {
			if (!@chown($this->uri['path'], $this->user)) {
				unlink($this->uri['path']);
				Daemon::log('Couldn\'t change owner of the socket \'' . $this->uri['path'] . '\' to \'' . $this->user . '\'.');
				return false;
			}
		}
		$this->setFd($sock);
		return true;	
	}
}
