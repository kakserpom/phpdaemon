<?php
namespace PHPDaemon\Core;

use PHPDaemon\Config\Entry\Generic;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Thread;
use PHPDaemon\Utils\DateTime;
use PHPDaemon\Utils\ShmEntity;
use PHPDaemon\Utils\Terminal;

/**
 * Bootstrap for PHPDaemon
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Bootstrap {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Master process ID
	 * @var integer
	 */
	protected static $pid;

	/**
	 * List of commands
	 * @var array
	 */
	protected static $commands = [
		'start', 'stop', 'hardstop', 'gracefulstop', 'update', 'reload', 'restart', 'hardrestart', 'fullstatus', 'status', 'configtest', 'log', 'runworker', 'ipcpath', 'reopenlog'
	];

	/**
	 * Command-line params
	 * @var array
	 */
	protected static $params = [
		'pid-file'     => [
			'val'  => '/path/to/pid-file',
			'desc' => 'Pid file'
		],
		'max-requests' => [
			'desc' => 'Maximum requests to worker before respawn',
			'val'  => [
				'n' => 'Count'
			]
		],
		'path'         => [
			'desc' => 'Path to your application resolver',
			'val'  => '/path/to/resolver.php'
		],
		'config-file'  => [
			'desc' => 'Paths to configuration file separated by semicolon. First found will be used.',
			'val'  => '/path/to/file'
		],
		'logging'      => [
			'desc' => 'Logging status',
			'val'  => [
				'0' => 'Disabled',
				'1' => 'Enabled'
			]
		],
		'log-storage'  => [
			'desc' => 'Log storage',
			'val'  => '/path/to/file'
		],
		'user'         => [
			'desc' => 'User of master process',
			'val'  => 'username'
		],
		'group'        => [
			'desc' => 'Group of master process',
			'val'  => 'groupname'
		],
		'help'         => 'This help information'
	];

	/**
	 * Actions on early startup.
	 * @param string Optional. Config file path.
	 * @return void
	 */
	public static function init($configFile = null) {
		if (!version_compare(PHP_VERSION, '5.4.0', '>=')) {
			Daemon::log('PHP >= 5.4.0 required.');
			return;
		}

		//run without composer
		if (!function_exists('setTimeout')) {
			require 'PHPDaemon/Utils/func.php';
		}

		Daemon::initSettings();
		FileSystem::init();
		Daemon::$runName = basename($_SERVER['argv'][0]);

		$error   = FALSE;
		$argv    = $_SERVER['argv'];
		$runmode = isset($argv[1]) ? str_replace('-', '', $argv[1]) : '';
		$args    = Bootstrap::getArgs($argv);

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
		elseif ('help' === $runmode) {
			self::printHelp();
			exit;
		}

		$n = null;
		if ('log' === $runmode) {
			if (isset($args['n'])) {
				$n = $args['n'];
				unset($args['n']);
			}
			else {
				$n = 20;
			}
		}

		if (isset($configFile)) {
			Daemon::$config->configfile->setHumanValue($configFile);
		}
		if (isset($args['configfile'])) {
			Daemon::$config->configfile->setHumanValue($args['configfile']);
		}

		if (!Daemon::$config->loadCmdLineArgs($args)) {
			$error = true;
		}

		if (!Daemon::loadConfig(Daemon::$config->configfile->value)) {
			$error = true;
		}

		if ('log' === $runmode) {
			passthru('tail -n ' . $n . ' -f ' . escapeshellarg(Daemon::$config->logstorage->value));
			exit;
		}

		if (extension_loaded('apc') && ini_get('apc.enabled')) {
			Daemon::log('Detected pecl-apc extension enabled. Usage of bytecode caching (APC/eAccelerator/xcache/...)  makes no sense at all in case of using phpDaemon \'cause phpDaemon includes files just in time itself.');
		}

		if (isset(Daemon::$config->locale->value) && Daemon::$config->locale->value !== '') {
			setlocale(LC_ALL, array_map('trim', explode(',', Daemon::$config->locale->value)));
		}

		if (
				Daemon::$config->autoreimport->value
				&& !is_callable('runkit_import')
		) {
			Daemon::log('[WARN] runkit extension not found. You should install it or disable --auto-reimport. Non-critical error.');
		}

		if (!is_callable('posix_kill')) {
			Daemon::log('[EMERG] Posix not found. You should compile PHP without \'--disable-posix\'.');
			$error = true;
		}

		if (!is_callable('pcntl_signal')) {
			Daemon::log('[EMERG] PCNTL not found. You should compile PHP with \'--enable-pcntl\'.');
			$error = true;
		}

		if (extension_loaded('libevent')) {
			Daemon::log('[EMERG] libevent extension found. You have to remove libevent.so extension.');
			$error = true;
		}

		$eventVer     = '1.6.1';
		$eventVerType = 'stable';
		if (!Daemon::loadModuleIfAbsent('event', $eventVer . '-' . $eventVerType)) {
			Daemon::log('[EMERG] event extension >= ' . $eventVer . ' not found (or OUTDATED). You have to install it. `pecl install http://pecl.php.net/get/event`');
			$error = true;
		}

		if (!is_callable('socket_create')) {
			Daemon::log('[EMERG] Sockets extension not found. You should compile PHP with \'--enable-sockets\'.');
			$error = true;
		}

		if (!is_callable('shmop_open')) {
			Daemon::log('[EMERG] Shmop extension not found. You should compile PHP with \'--enable-shmop\'.');
			$error = true;
		}

		if (!isset(Daemon::$config->user)) {
			Daemon::log('[EMERG] You must set \'user\' parameter.');
			$error = true;
		}

		if (!isset(Daemon::$config->path)) {
			Daemon::log('[EMERG] You must set \'path\' parameter (path to your application resolver).');
			$error = true;
		}

		if (!file_exists(Daemon::$config->pidfile->value)) {
			if (!touch(Daemon::$config->pidfile->value)) {
				Daemon::log('[EMERG] Couldn\'t create pid-file \'' . Daemon::$config->pidfile->value . '\'.');
				$error = true;
			}

			Bootstrap::$pid = 0;
		}
		elseif (!is_file(Daemon::$config->pidfile->value)) {
			Daemon::log('Pid-file \'' . Daemon::$config->pidfile->value . '\' must be a regular file.');
			Bootstrap::$pid = FALSE;
			$error          = true;
		}
		elseif (!is_writable(Daemon::$config->pidfile->value)) {
			Daemon::log('Pid-file \'' . Daemon::$config->pidfile->value . '\' must be writable.');
			$error = true;
		}
		elseif (!is_readable(Daemon::$config->pidfile->value)) {
			Daemon::log('Pid-file \'' . Daemon::$config->pidfile->value . '\' must be readable.');
			Bootstrap::$pid = FALSE;
			$error          = true;
		}
		else {
			Bootstrap::$pid = (int)file_get_contents(Daemon::$config->pidfile->value);
		}

		if (Daemon::$config->chroot->value !== '/') {
			if (posix_getuid() != 0) {
				Daemon::log('You must have the root privileges to change root.');
				$error = true;
			}
		}

		$pathList = preg_split('~\s*;\s*~', Daemon::$config->path->value);
		$found    = false;
		foreach ($pathList as $path) {
			if (@is_file($path)) {
				Daemon::$appResolverPath = $path;
				$found                        = true;
				break;
			}
		}
		if (!$found) {
			Daemon::log('Your application resolver \'' . Daemon::$config->path->value . '\' is not available (config directive \'path\').');
			$error = true;
		}
		
		Daemon::$appResolver = require Daemon::$appResolverPath;

		if (
				isset(Daemon::$config->group->value)
				&& is_callable('posix_getgid')
		) {
			if (($sg = posix_getgrnam(Daemon::$config->group->value)) === FALSE) {
				Daemon::log('Unexisting group \'' . Daemon::$config->group->value . '\'. You have to replace config-variable \'group\' with existing group-name.');
				$error = true;
			}
			elseif (($sg['gid'] != posix_getgid()) && (posix_getuid() != 0)) {
				Daemon::log('You must have the root privileges to change group.');
				$error = true;
			}
		}

		if (
				isset(Daemon::$config->user->value)
				&& is_callable('posix_getuid')
		) {
			if (($su = posix_getpwnam(Daemon::$config->user->value)) === FALSE) {
				Daemon::log('Unexisting user \'' . Daemon::$config->user->value . '\', user not found. You have to replace config-variable \'user\' with existing username.');
				$error = true;
			}
			elseif (
					($su['uid'] != posix_getuid())
					&& (posix_getuid() != 0)
			) {
				Daemon::log('You must have the root privileges to change user.');
				$error = true;
			}
		}

		if (
				isset(Daemon::$config->minspareworkers->value)
				&& Daemon::$config->minspareworkers->value > 0
				&& isset(Daemon::$config->maxspareworkers->value)
				&& Daemon::$config->maxspareworkers->value > 0
		) {
			if (Daemon::$config->minspareworkers->value > Daemon::$config->maxspareworkers->value) {
				Daemon::log('\'minspareworkers\' cannot be greater than \'maxspareworkers\'.');
				$error = true;
			}
		}

		if (isset(Daemon::$config->addincludepath->value)) {
			ini_set('include_path', ini_get('include_path') . ':' . implode(':', Daemon::$config->addincludepath->value));
		}

		if (
				isset(Daemon::$config->minworkers->value)
				&& isset(Daemon::$config->maxworkers->value)
		) {
			if (Daemon::$config->minworkers->value > Daemon::$config->maxworkers->value) {
				Daemon::$config->minworkers->value = Daemon::$config->maxworkers->value;
			}
		}

		if ($runmode === 'start') {
			if ($error === FALSE) {
				Bootstrap::start();
			}
			else {
				exit(6);
			}
		}
		elseif ($runmode === 'runworker') {
			if ($error === FALSE) {
				Bootstrap::runworker();
			}
			else {
				exit(6);
			}
		}
		elseif (
				$runmode === 'status'
				|| $runmode === 'fullstatus'
		) {
			$status = Bootstrap::$pid && Thread\Generic::ifExistsByPid(Bootstrap::$pid);
			echo '[STATUS] phpDaemon ' . Daemon::$version . ' is ' . ($status ? 'running' : 'NOT running') . ' (' . Daemon::$config->pidfile->value . ").\n";

			if (
					$status
					&& ($runmode === 'fullstatus')
			) {
				echo 'Uptime: ' . DateTime::diffAsText(filemtime(Daemon::$config->pidfile->value), time()) . "\n";

				Daemon::$shm_wstate = new ShmEntity(Daemon::$config->pidfile->value, Daemon::SHM_WSTATE_SIZE, 'wstate');

				$stat = Daemon::getStateOfWorkers();

				echo "State of workers:\n";
				echo "\tTotal: " . $stat['alive'] . "\n";
				echo "\tIdle: " . $stat['idle'] . "\n";
				echo "\tBusy: " . $stat['busy'] . "\n";
				echo "\tShutdown: " . $stat['shutdown'] . "\n";
				echo "\tPre-init: " . $stat['preinit'] . "\n";
				echo "\tInit: " . $stat['init'] . "\n";
			}

			echo "\n";
		}
		elseif ($runmode === 'update') {
			if (
					(!Bootstrap::$pid)
					|| (!posix_kill(Bootstrap::$pid, SIGHUP))
			) {
				echo '[UPDATE] ERROR. It seems that phpDaemon is not running' . (Bootstrap::$pid ? ' (PID ' . Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode === 'reopenlog') {
			if (
					(!Bootstrap::$pid)
					|| (!posix_kill(Bootstrap::$pid, SIGUSR1))
			) {
				echo '[REOPEN-LOG] ERROR. It seems that phpDaemon is not running' . (Bootstrap::$pid ? ' (PID ' . Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode === 'reload') {
			if (
					(!Bootstrap::$pid)
					|| (!posix_kill(Bootstrap::$pid, SIGUSR2))
			) {
				echo '[RELOAD] ERROR. It seems that phpDaemon is not running' . (Bootstrap::$pid ? ' (PID ' . Bootstrap::$pid . ')' : '') . ".\n";
			}
		}
		elseif ($runmode === 'restart') {
			if ($error === FALSE) {
				Bootstrap::stop(2);
				Bootstrap::start();
			}
		}
		elseif ($runmode === 'hardrestart') {
			Bootstrap::stop(3);
			Bootstrap::start();
		}
		elseif ($runmode === 'ipcpath') {
			$i = Daemon::$appResolver->getInstanceByAppName('\PHPDaemon\IPCManager\IPCManager');
			echo $i->getSocketUrl() . PHP_EOL;
		}
		elseif ($runmode === 'configtest') {
			$term = new Terminal;
			$term->enableColor();

			echo "\n";

			$rows = [];

			$rows[] = [
				'parameter' => 'PARAMETER',
				'value'     => 'VALUE',
				'_color'    => '37',
				'_bold'     => true,
			];

			foreach (Daemon::$config as $name => $entry) {
				if (!$entry instanceof Generic) {
					continue;
				}
				
				$row = [
					'parameter' => $name,
					'value'     => var_export($entry->humanValue, true),
				];

				if ($entry->defaultValue != $entry->humanValue) {
					$row['value'] .= ' (' . var_export($entry->defaultValue, true) . ')';
				}

				$rows[] = $row;
			}

			$term->drawtable($rows);

			echo "\n";
		}
		elseif ($runmode === 'stop') {
			Bootstrap::stop();
		}
		elseif ($runmode === 'gracefulstop') {
			Bootstrap::stop(4);
		}
		elseif ($runmode === 'hardstop') {
			echo '[HARDSTOP] Sending SIGINT to ' . Bootstrap::$pid . '... ';

			$ok = Bootstrap::$pid && posix_kill(Bootstrap::$pid, SIGINT);

			echo $ok ? 'OK.' : 'ERROR. It seems that phpDaemon is not running.';

			if ($ok) {
				$i = 0;

				while ($r = Thread\Generic::ifExistsByPid(Bootstrap::$pid)) {
					usleep(500000);

					if ($i === 9) {
						echo "\nphpDaemon master-process hasn't finished. Sending SIGKILL... ";
						posix_kill(Bootstrap::$pid, SIGKILL);
						sleep(0.2);
						if (!Thread\Generic::ifExistsByPid(Bootstrap::$pid)) {
							echo " Oh, his blood is on my hands :'(";
						}
						else {
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

	/**
	 * Print ussage
	 * @return void
	 */
	protected static function printUsage() {
		echo 'usage: ' . Daemon::$runName . " (start|(hard|graceful)stop|update|reload|reopenlog|(hard)restart|fullstatus|status|configtest|log|runworker|help) ...\n";
	}

	/**
	 * Print help
	 * @return void
	 */
	protected static function printHelp() {
		$term = new Terminal();

		echo 'phpDaemon ' . Daemon::$version . ". http://phpdaemon.net\n";

		self::printUsage();

		echo "\nAlso you can use some optional parameters to override the same configuration variables:\n";

		foreach (self::$params as $name => $desc) {
			if (empty($desc)) {
				continue;
			}
			elseif (!is_array($desc)) {
				$term->drawParam($name, $desc);
			}
			else {
				$term->drawParam(
					$name,
					isset($desc['desc']) ? $desc['desc'] : '',
					isset($desc['val']) ? $desc['val'] : ''
				);
			}
		}

		echo "\n";
	}

	/**
	 * Start master.
	 * @return void
	 */
	public static function start() {
		if (
				Bootstrap::$pid
				&& Thread\Generic::ifExistsByPid(Bootstrap::$pid)
		) {
			Daemon::log('[START] phpDaemon with pid-file \'' . Daemon::$config->pidfile->value . '\' is running already (PID ' . Bootstrap::$pid . ')');
			exit(6);
		}

		Daemon::init();
		$pid = Daemon::spawnMaster();
		file_put_contents(Daemon::$config->pidfile->value, $pid);
	}

	/**
	 * Runworker.
	 * @return void
	 */
	public static function runworker() {
		Daemon::log('PLEASE USE runworker COMMAND ONLY FOR DEBUGGING PURPOSES.');
		Daemon::init();
		Daemon::runWorker();
	}

	/**
	 * Stop script.
	 * @param int $mode
	 * @return void
	 */
	public static function stop($mode = 1) {
		$ok = Bootstrap::$pid && posix_kill(Bootstrap::$pid, $mode === 3 ? SIGINT : (($mode === 4) ? SIGTSTP : SIGTERM));

		if (!$ok) {
			echo '[WARN]. It seems that phpDaemon is not running' . (Bootstrap::$pid ? ' (PID ' . Bootstrap::$pid . ')' : '') . ".\n";
		}

		if (
				$ok
				&& ($mode > 1)
		) {
			$i = 0;

			while ($r = Thread\Generic::ifExistsByPid(Bootstrap::$pid)) {
				usleep(10000);
				++$i;
			}
		}
	}

	/**
	 * Parses command-line arguments.
	 * @param array $args $_SERVER ['argv']
	 * @return array Arguments
	 */
	public static function getArgs($args) {
		$out      = [];
		$last_arg = NULL;

		for ($i = 1, $il = sizeof($args); $i < $il; ++$i) {
			if (preg_match('~^--(.+)~', $args[$i], $match)) {
				$parts = explode('=', $match[1]);
				$key   = preg_replace('~[^a-z0-9]+~', '', $parts[0]);

				if (isset($parts[1])) {
					$out[$key] = $parts[1];
				}
				else {
					$out[$key] = true;
				}

				$last_arg = $key;
			}
			elseif (preg_match('~^-([a-zA-Z0-9]+)~', $args[$i], $match)) {
				$key = null;
				for ($j = 0, $jl = strlen($match[1]); $j < $jl; ++$j) {
					$key       = $match[1]{$j};
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
