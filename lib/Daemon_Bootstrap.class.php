<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_Bootstrap
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Bootstrap class.
/**************************************************************************/

class Daemon_Bootstrap {

	public static $pidfile;
	public static $pid;

	private static $commands = array(
		'start', 'stop', 'hardstop', 'update', 'reload', 'restart', 'hardrestart', 'fullstatus', 'status', 'configtest'
	);

	private static $params = array(
		'pid-file' => array(
			'val' => '/path/to/pid-file',
			'desc' => 'Pid file'
		),
		'max-requests' => array(
			'desc' => 'Maximum requests to worker before respawn',
			'val'  => array(
				'n' => 'Count'
			),
			'skey' => 'maxrequests'
		),
		'path' => array(
			'desc' => 'Path to your application resolver',
			'val'  => '/path/to/resolver.php',
			'skey' => 'path'
		),
		'config-file' => array(
			'desc' => 'Paths to configuration file separated by semicolon. First found will be used.',
			'val'  => '/path/to/config.conf',
			'skey' => 'configfile'
		),
		'logging' => array(
			'desc' => 'Logging status',
			'val'  => array(
				'0' => 'Disabled',
				'1' => 'Enabled'
			)
		),
		'log-storage' => array(
			'desc' => 'Log storage',
			'val'  => '/path/to/storage.log',
			'skey' => 'logstorage'
		),
		'user' => array(
			'desc' => 'User of master process',
			'val'  => 'username',
			'skey' => 'user'
		),
		'group' => array(
			'desc' => 'Group of master process',
			'val'  => 'groupname',
			'skey' => 'group'
		),
		'help' => 'This help information'
	);

	/**
	 * @method init
	 * @description Actions on early startup.
	 * @return void
	 */
	public static function init() {
		Daemon::initSettings();
		Daemon::$runName = basename($_SERVER['argv'][0]);

		$error = FALSE;
		$argv = $_SERVER['argv'];
		$runmode = isset($argv[1]) ? str_replace('-', '', $argv[1]) : '';
		$args = Daemon_Bootstrap::getArgs($argv);

		if (
			!isset(self::$params[$runmode])
			&& !in_array($runmode, self::$commands)
		) {
			if ('' !== $runmode) {
				echo('Unrecognized command: ' . $runmode . "\n");
			}

			self::printUsage();
			exit;
		}

		if (isset($args[$k = 'configfile'])) {
			Daemon::$settings[$k] = $args[$k];
		}

		if (
			isset(Daemon::$settings['configfile'])
			&& !Daemon::loadConfig(Daemon::$settings['configfile'])
		) {
			$error = TRUE;
		}

		if (!Daemon::loadSettings($args)) {
			$error = TRUE;
		}

		// we need some settings for help so config is loaded before
		if ('help' === $runmode) {
			self::printHelp();
			exit;
		}

		if (version_compare(PHP_VERSION, '5.3.0', '>=') === 1) {
			Daemon::log('PHP >= 5.3.0 required.');
			$error = TRUE;
		}
		
		if (isset(Daemon::$settings['locale'])) {
			setlocale(LC_ALL,explode(',', Daemon::$settings['locale']));
		}
		
		if (
			Daemon::$settings['autoreimport'] 
			&& !is_callable('runkit_import')
		) {
			Daemon::log('runkit extension not found. You should install it or disable --auto-reimport.');
			$error = TRUE;
		}
		
		if (!is_callable('posix_kill')) {
			Daemon::log('Posix not found. You should compile PHP without \'--disable-posix\'.');
			$error = TRUE;
		}
	
		if (!is_callable('pcntl_signal')) {
			Daemon::log('PCNTL not found. You should compile PHP with \'--enable-pcntl\'.');
			$error = TRUE;
		}
	
		if (!is_callable('event_base_new')) {
			Daemon::log('libevent extension not found. You have to install libevent from pecl (http://pecl.php.net/package/libevent). `svn checkout http://svn.php.net/repository/pecl/libevent pecl-libevent`.');
			$error = TRUE;
		}
	
		if (!is_callable('socket_create')) {
			Daemon::log('Sockets extension not found. You should compile PHP with \'--enable-sockets\'.');
			$error = TRUE;
		}
		
		if (!is_callable('shmop_open')) {
			Daemon::log('Shmop extension not found. You should compile PHP with \'--enable-shmop\'.');
			$error = TRUE;
		}
		
		if (!isset(Daemon::$settings['user'])) {
			Daemon::log('You must set \'user\' parameter.');
			$error = TRUE;
		}
		
		if (!isset(Daemon::$settings['path'])) {
			Daemon::log('You must set \'path\' parameter (path to your application resolver).');
			$error = TRUE;
		}
		
		Daemon_Bootstrap::$pidfile = realpath(Daemon::$settings['pidfile']);
		
		if (!Daemon_Bootstrap::$pidfile) {
			Daemon_Bootstrap::$pidfile = Daemon::$settings['pidfile'];
		}
		
		if (!file_exists(Daemon_Bootstrap::$pidfile)) {
			if (!touch(Daemon_Bootstrap::$pidfile)) {
				Daemon::log('Couldn\'t create pid-file \'' . Daemon_Bootstrap::$pidfile . '\'.');
				$error = TRUE;
			}

			Daemon_Bootstrap::$pid = 0;
		}
		elseif (!is_file(Daemon_Bootstrap::$pidfile)) {
			Daemon::log('Pid-file \'' . Daemon_Bootstrap::$pidfile . '\' must be a regular file.');
			Daemon_Bootstrap::$pid = FALSE;
			$error = TRUE;
		}
		elseif (!is_writable(Daemon_Bootstrap::$pidfile)) {
			Daemon::log('Pid-file \'' . Daemon_Bootstrap::$pidfile . '\' must be writable.');
			$error = TRUE;
		}
		elseif (!is_readable(Daemon_Bootstrap::$pidfile)) {
			Daemon::log('Pid-file \'' . Daemon_Bootstrap::$pidfile . '\' must be readable.');
			Daemon_Bootstrap::$pid = FALSE;
			$error = TRUE;
		} else {
			Daemon_Bootstrap::$pid = (int) file_get_contents(Daemon_Bootstrap::$pidfile);
		}
		
		if (Daemon::$settings['chroot'] !== '/') {
			if (posix_getuid() != 0) {
				Daemon::log('You must have the root privileges to change root.');
				$error = TRUE;
			}
		}
		
		if (!@is_file(Daemon::$pathReal)) {
			Daemon::log('Your application resolver \'' . Daemon::$settings['path'] . '\' is not available.');
			$error = TRUE;
		}

		if (
			isset(Daemon::$settings['group']) 
			&& is_callable('posix_getgid')
		) {
			if (($sg = posix_getgrnam(Daemon::$settings['group'])) === FALSE) {
				Daemon::log('Unexisting group \'' . Daemon::$settings['group'] . '\'. You have to replace config-variable \'group\' with existing group-name.');
				$error = TRUE;
			}
			elseif (($sg['gid'] != posix_getgid()) && (posix_getuid() != 0)) {
				Daemon::log('You must have the root privileges to change group.');
				$error = TRUE;
			}
		}
		
		if (
			isset(Daemon::$settings['user']) 
			&& is_callable('posix_getuid')
		) {
			if (($su = posix_getpwnam(Daemon::$settings['user'])) === FALSE) {
				Daemon::log('Unexisting user \'' . Daemon::$settings['user'] . '\', user not found. You have to replace config-variable \'user\' with existing username.');
				$error = TRUE;
			}
			elseif (
				($su['uid'] != posix_getuid()) 
				&& (posix_getuid() != 0)
			) {
				Daemon::log('You must have the root privileges to change user.');
				$error = TRUE;
			}
		}
		
		if (
			isset(Daemon::$settings['minspareworkers']) 
			&& isset(Daemon::$settings['maxspareworkers'])
		) {
			if (Daemon::$settings['minspareworkers'] > Daemon::$settings['maxspareworkers']) {
				Daemon::log('\'minspareworkers\' cannot be greater than \'maxspareworkers\'.');
				$error = TRUE;
			}
		}
		
		if (
			isset(Daemon::$settings['minworkers']) 
			&& isset(Daemon::$settings['maxworkers'])
		) {
			if (Daemon::$settings['minworkers'] > Daemon::$settings['maxworkers']) {
				Daemon::$settings['maxworkers'] = Daemon::$settings['minworkers'];
			}
		}
		
		if ($runmode == 'start') {
			if ($error === FALSE) {
				Daemon_Bootstrap::start();
			}
		}
		elseif (
			$runmode == 'status' 
			|| $runmode == 'fullstatus'
		) {
			$status = Daemon_Bootstrap::$pid && posix_kill(Daemon_Bootstrap::$pid, SIGTTIN);
			echo '[STATUS] phpDaemon ' . Daemon::$version . ' is ' . ($status ? 'running' : 'NOT running') . ' (' . Daemon_Bootstrap::$pidfile . ").\n";

			if (
				$status 
				&& ($runmode == 'fullstatus')
			) {
				echo 'Uptime: ' . Daemon::date_period_text(filemtime(Daemon_Bootstrap::$pidfile), time()) . "\n";

				Daemon::$shm_wstate = Daemon::shmop_open(Daemon::$settings['ipcwstate'], 0, 'wstate', FALSE);

				$stat = Daemon::getStateOfWorkers();

				echo "State of workers:\n";
				echo "\tTotal: " . $stat['alive'] . "\n";
				echo "\tIdle: " . $stat['idle'] . "\n";
				echo "\tBusy: " . $stat['busy'] . "\n";
				echo "\tShutdown: " . $stat['shutdown'] . "\n";
				echo "\tPre-init: " . $stat['preinit'] . "\n";
				echo "\tWait-init: " . $stat['waitinit'] . "\n";
				echo "\tInit: " . $stat['init'] . "\n";
			}

			echo "\n";
		}
		elseif ($runmode == 'update') {
			if (
				(!Daemon_Bootstrap::$pid) 
				|| (!posix_kill(Daemon_Bootstrap::$pid, SIGHUP))
			) {
				echo '[UPDATE] ERROR. It seems that phpDaemon is not running' . (Daemon_Bootstrap::$pid ? ' (PID ' . Daemon_Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode == 'reopenlog') {
			if (
				(!Daemon_Bootstrap::$pid) 
				|| (!posix_kill(Daemon_Bootstrap::$pid,SIGUSR1))
			) {
				echo '[REOPEN-LOG] ERROR. It seems that phpDaemon is not running' . (Daemon_Bootstrap::$pid ? ' (PID ' . Daemon_Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode == 'reload') {
			if (
				(!Daemon_Bootstrap::$pid) 
				|| (!posix_kill(Daemon_Bootstrap::$pid,SIGUSR2))
			) {
				echo '[RELOAD] ERROR. It seems that phpDaemon is not running' . (Daemon_Bootstrap::$pid ? ' (PID ' . Daemon_Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode == 'restart') {
			Daemon_Bootstrap::stop(2);
			Daemon_Bootstrap::start();
		}
		elseif ($runmode == 'hardrestart') {
			Daemon_Bootstrap::stop(3);
			Daemon_Bootstrap::start();
		}
		elseif ($runmode == 'configtest') {
			$term = new Terminal;
			$term->enable_color = TRUE;

			echo "\n";

			$rows = array();

			$rows[] = array(
				'parameter' => 'PARAMETER',
				'value'     => 'VALUE',
				'_color'    => '37',
				'_bold'     => TRUE,
			);
			
			foreach (Daemon::$settings as $name => &$value) {
				$row = array(
					'parameter' => $name,
					'value'     => var_export($value, TRUE),
				);

				if (isset(Daemon::$parsedSettings[$name])) {
					$row['value'] .= ' (' . Daemon::$parsedSettings[$name] . ')';
				}
			
				$rows[] = $row;
			}

			$term->drawtable($rows);

			echo "\n";
		}
		elseif ($runmode == 'stop') {
			Daemon_Bootstrap::stop();
		}
		elseif ($runmode == 'hardstop') {
			echo '[HARDSTOP] Sending SIGINT to ' . Daemon_Bootstrap::$pid . '... ';

			$ok = Daemon_Bootstrap::$pid && posix_kill(Daemon_Bootstrap::$pid,SIGINT);

			echo $ok ? 'OK.' : 'ERROR. It seems that phpDaemon is not running.';

			if ($ok) {
				$i = 0;
		
				while ($r = posix_kill(Daemon_Bootstrap::$pid, SIGTTIN)) {
					usleep(500000);
		
					if ($i == 9) {
						echo "\nphpDaemon master-process hasn't finished. Sending SIGKILL... ";
						posix_kill(Daemon_Bootstrap::$pid, SIGKILL);

						if (!posix_kill(Daemon_Bootstrap::$pid, SIGTTIN)) {
							echo " Oh, his blood is on my hands :'(";
						} else {
							echo "ERROR: Process alive. Permissions?";
						}

						break;
					}

					++$i;
				}
			}
			
			echo "\n";
		}
	}

	private static function printUsage() {
		echo 'usage: ' . Daemon::$runName . " (start|(hard)stop|update|reload|(hard)restart|fullstatus|status|configtest|help) ...\n";
	}

	private static function printHelp() {
		$term = new Terminal();

		echo 'phpDaemon ' . Daemon::$version . ". Made in Russia. http://phpdaemon.net\n";

		self::printUsage();

		echo "\nAlso you can use some optional parameters to override the same configuration variables:\n";

		foreach(self::$params as $name => $desc) {
			if (empty($desc)) {
				continue;
			} 
			elseif (!is_array($desc)) {
				$term->drawParam($name, $desc);
			} else {
				$term->drawParam(
					$name,
					isset($desc['desc']) ? $desc['desc'] : '',
					isset($desc['val']) ? $desc['val'] : '',
					isset($desc['skey']) && isset(Daemon::$settings[$desc['skey']]) 
						? Daemon::$settings[$desc['skey']]
						: ''
				);
			}
		}
	}
	
	/**
	 * @method start
	 * @description Start script.
	 * @return void
	 */
	public static function start() {
		if (
			Daemon_Bootstrap::$pid 
			&& posix_kill(Daemon_Bootstrap::$pid, SIGTTIN)
		) {
			Daemon::log('[START] phpDaemon with pid-file \'' . Daemon_Bootstrap::$pidfile . '\' is running already (PID ' . Daemon_Bootstrap::$pid . ')');
			exit;
		}

		Daemon::init();
		$pid = Daemon::spawnMaster();
		file_put_contents(Daemon_Bootstrap::$pidfile, $pid);
	}

	/**
	 * @method stop
	 * @description Stop script.
	 * @return void
	 */
	public static function stop($mode = 1) {
		$ok = Daemon_Bootstrap::$pid && posix_kill(Daemon_Bootstrap::$pid, $mode === 3 ? SIGINT:SIGTERM);

		if (!$ok) {
			echo '[STOP] ERROR. It seems that phpDaemon is not running' . (Daemon_Bootstrap::$pid ? ' (PID ' . Daemon_Bootstrap::$pid . ')' : '') . ".\n";
		}
		
		if (
			$ok 
			&& ($mode > 1)
		) {
			$i = 0;

			while ($r = posix_kill(Daemon_Bootstrap::$pid, SIGTTIN)) {
				usleep(500000);
				++$i;
			}
		}
	}

	/**
	 * @method getArgs
	 * @param array $_SERVER['argv']
	 * @description Parses command-line arguments.
	 * @return void
	 */
	public static function getArgs($args) {
		$out = array();
		$last_arg = NULL;
		
		for($i = 1, $il = sizeof($args); $i < $il; ++$i) {
			if (preg_match('~^--(.+)~', $args[$i], $match)) {
				$parts = explode('=', $match[1]);
				$key = preg_replace('~[^a-z0-9]+~', '', $parts[0]);

				if (isset($parts[1])) {
					$out[$key] = $parts[1];
				} else {
					$out[$key] = TRUE;
				}

				$last_arg = $key;
			}
			elseif (preg_match('~^-([a-zA-Z0-9]+)~', $args[$i], $match)) {
				for ($j = 0, $jl = strlen($match[1]); $j < $jl; ++$j) {
					$key = $match[1]{$j};
					$out[$key] = true;
				}
		
				$last_arg = $key;
			}
			elseif ($last_arg !== NULL) {
				$out[$last_arg] = $args[$i];
			}
		}
		
		return $out;
	}
}
