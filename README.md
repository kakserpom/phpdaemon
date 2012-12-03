## phpDaemon:

http://github.com/kakserpom/phpdaemon

Asynchronous framework in PHP. It has a huge number of features. Designed for highload.
Each worker is able to handle thousands of simultaneous connections holding beer can.
Main features and possibilites:

 * Powerful servers: HTTP, FastCGI, FlashPolicy, Ident, Socks4/5.
 * Many bundled clients like DNS, MySQL, Postgresql, Memcache, MongoDB, Redis, HTTP, IRC, Jabber, ICMP, Valve games client, etc.
 * Asynchrounous Filesystem I/O (using eio).
 * Many useful built-in applications like CGI.
 * Interactive debug console.
 * Dynamic spawning workers.
 * Chroot & Chdir for workers.
 * Automatic graceful reloading user's scripts when it's updated.
 * Graceful worker shutdown (and re-spawn if necessary) by the following limits: memory, query counter, idle time.

Installation guide: http://github.com/kakserpom/phpdaemon/wiki/Installation-(common)

Master process understands signals:
	
	SIGINT, SIGTERM, SIGQUIT - termination.
	SIGHUP - update config from file.
	SIGUSR1 - reopen log-file.
	SIGUSR2 - graceful restart all workers.

Mail listing: phpdaemon@googlegroups.com
Maintainer: kak.serpom.po.yaitsam@gmail.com
