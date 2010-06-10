## phpDaemon:

http://github.com/kakserpom/phpdaemon

True async. framework with API for PHP. It has many features. Designed for highload.
Main features and possibilites:

 * Powerful WebSocket and HTTP servers.
 * True FastCGI for PHP.
 * Many bundled clients like MySQL, Memcache, MongoDB, etc.
 * Many useful built-in applications like FlashPolicy server, SocksServer, CGI-server, etc...
 * Interactive debug console.
 * Dynamic spawning workers.
 * Chroot & Chdir for workers.
 * Automatic graceful reloading user's scripts when it's updated.
 * Graceful worker shutdown (and re-spawn if necessary) by the following limits: memory, query counter, idle time.

Also, you can build binary application server using compiler like PHC (http://phpcompiler.org/).

Installation guide: http://wiki.github.com/kakserpom/phpdaemon/install

Master process understands signals:
	
	SIGINT, SIGTERM, SIGQUIT - termination.
	SIGHUP - update config from file.
	SIGUSR1 - reopen log-file.
	SIGUSR2 - graceful restart all workers.

Maintainer: kak.serpom.po.yaitsam@gmail.com
