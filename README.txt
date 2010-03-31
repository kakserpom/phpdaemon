
						phpDaemon
http://github.com/kakserpom/phpdaemon

True async. server with API for PHP. It has many features.
Main features and possibilites:
 - True FastCGI for PHP.
 - Interactive debug console.
 - Dynamic spawning workers.
 - Chroot & Chdir for workers.
 - Automatic graceful reloading user's script.
 - Graceful worker shutdown (and re-spawn if necessary) by the following limits: memory, query counter, idle time.

Also, you can build binary application server using compiler like PHC (http://phpcompiler.org/).
 

Benchmark (example script):
	Requests per second:    4784.80 [#/sec] (mean)

Installation guide: http://wiki.github.com/kakserpom/phpdaemon/install

Master process understands signals:
	
	SIGINT, SIGTERM, SIGQUIT - termination.
	SIGHUP - update config from file.
	SIGUSR1 - reopen log-file.
	SIGUSR2 - graceful restart all workers.

If you have a need of develope non-trivial modules, we can discuss that.
Maintainer: kak.serpom.po.yaitsam@gmail.com
