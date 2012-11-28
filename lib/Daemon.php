<?php

/**
 * Daemon "namespace"
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon
{

    const SUPPORT_RUNKIT_SANDBOX = 0;
    const SUPPORT_RUNKIT_MODIFY = 1;
    const SUPPORT_RUNKIT_INTERNAL_MODIFY = 2;
    const SUPPORT_RUNKIT_IMPORT = 3;

    /**
     * PHPDaemon version
     * @var string
     * @static
     */
    public static $version;

    /**
     * PHPDaemon start time
     * @var integer
     * @static
     */
    public static $startTime;

    /**
     * Log file resource
     * @var resource
     * @static
     */
    public static $logPointer;

    /**
     * @var File
     * @static
     */
    public static $logPointerAsync;

    /**
     * Supported things array
     * @var bool[]
     * @static
     */
    private static $support = array();

    /**
     * @var Daemon_WorkerThread
     * @static
     */
    public static $process;

    /**
     * @var AppResolver
     * @static
     */
    public static $appResolver;

    /**
     * @var array|AppInstance[]
     * @static
     */
    public static $appInstances = array();

    /**
     * @var Request
     * @static
     */
    public static $req;

    /**
     * @var CallbackWrapper
     * @static
     */
    public static $context;

    /**
     * @var
     * @static
     * @deprecated
     */
    private static $workers;

    /**
     * @var ThreadCollection|Daemon_MasterThread[]
     * @static
     */
    private static $masters;

    /**
     * @see $_SERVER
     * @var string[]
     * @static
     */
    private static $initServerVar;

    /**
     * @var int
     * @static
     */
    public static $shm_wstate;

    /**
     * @var int
     * @static
     */
    private static $shm_wstate_size = 5120;

    /**
     * @var bool
     * @static
     */
    public static $reusePort;

    /**
     * Режим совместимости
     * @var bool
     * @static
     */
    public static $compatMode = false;

    /**
     * @var string
     * @static
     */
    public static $runName = 'phpDaemon';

    /**
     * @var Daemon_Config"
     * #static
     */
    public static $config;

    /**
     * Directory to AppResolver
     * @var string
     * @static
     */
    public static $appResolverPath;

    /**
     * @var bool
     * @static
     */
    public static $restrictErrorControl = false;

    /**
     * @see error_reporting
     * @var int
     * @static
     */
    public static $defaultErrorLevel;

    /**
     * Whether if the current execution stack contains ob-filter
     * @var bool
     * @static
     */
    public static $obInStack = false;

    /**
     * Loads default setting.
     * @return void
     * @static
     */
    public static function initSettings()
    {
        Daemon::$version = file_get_contents('VERSION', true);

        Daemon::$config = new Daemon_Config;

        // currently re-using listener ports across multiple processes is available
        // only in BSD flavour operating systems via SO_REUSEPORT socket option
        Daemon::$reusePort = 1 === preg_match("~BSD~i", php_uname('s'));

        if (Daemon::$reusePort && !defined("SO_REUSEPORT"))
            define("SO_REUSEPORT", 0x200); // FIXME: this is a BSD-only hack
    }

    /**
     * Periodic running the garbage collector
     * @return void
     * @static
     */
    public static function callAutoGC()
    {
        if (
            (Daemon::$config->autogc->value > 0)
            && (Daemon::$process->counterGC > 0)
            && (Daemon::$process->counterGC % Daemon::$config->autogc->value === 0)
        ) {
            gc_collect_cycles();
            ++Daemon::$process->counterGC;
        }
    }

    /**
     * Callback-function, output filter.
     * @param string $s - String.
     * @return string - buffer
     * @static
     */
    public static function outputFilter($s)
    {
        static $n = 0; // recursion counter

        if ($s === '') {
            return '';
        }
        ++$n;
        Daemon::$obInStack = true;
        if (
            Daemon::$config->obfilterauto->value
            && (Daemon::$req !== NULL)
        ) {
            Daemon::$req->out($s, false);

        } else {
            Daemon::log('Unexpected output (len. ' . strlen($s) . '): \'' . $s . '\'');
        }
        --$n;
        Daemon::$obInStack = $n > 0;
        return '';
    }

    /**
     * Display and logging of information about uncaught error
     * @param Exception $e
     * @return void
     * @static
     */
    public static function uncaughtExceptionHandler(Exception $e)
    {
        $msg = $e->getMessage();
        Daemon::log('Uncaught ' . get_class($e) . ' (' . $e->getCode() . ')' . (strlen($msg) ? ': ' . $msg : '') . ".\n" . $e->getTraceAsString());
        if (Daemon::$req) {
            Daemon::$req->out('<b>Uncaught ' . get_class($e) . ' (' . $e->getCode() . ')</b>' . (strlen($msg) ? ': ' . $msg : '') . '.<br />');
        }
    }

    /**
     * Display and logging of information about error
     * @param int $errNo
     * @param string $errStr
     * @param string $errFile
     * @param int $errLine
     * @param string $errContext
     * @return void
     * @static
     */
    public static function errorHandler($errNo, $errStr, $errFile, $errLine, $errContext)
    {
        $l = error_reporting();
        if ($l === 0) {
            if (!Daemon::$restrictErrorControl) {
                return;
            }
        } elseif ($l & $errNo === $l) {
            return;
        }

        static $errTypes = array(
            E_ERROR => 'Fatal error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse error',
            E_NOTICE => 'Notice',
            E_PARSE => 'Parse error',
            E_USER_ERROR => 'Fatal error (userland)',
            E_USER_WARNING => 'Warning (userland)',
            E_USER_NOTICE => 'Notice (userland)',
            E_STRICT => 'Notice (userland)',
            E_RECOVERABLE_ERROR => 'Fatal error (recoverable)',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'Deprecated (userland)',
        );
        $errType = $errTypes[$errNo];
        Daemon::log($errType . ': ' . $errStr . ' in ' . $errFile . ':' . $errLine . "\n" . Debug::backtrace());
        if (Daemon::$req) {
            Daemon::$req->out('<strong>' . $errType . '</strong>: ' . $errStr . ' in ' . basename($errFile) . ':' . $errLine . '<br />');
        }
    }

    /**
     * Performs initial actions.
     * @return void
     * @static
     */
    public static function init()
    {
        Daemon::$startTime = time();
        set_time_limit(0);

        Daemon::$defaultErrorLevel = error_reporting();
        Daemon::$restrictErrorControl = (bool)Daemon::$config->restricterrorcontrol->value;
        ob_start(array('Daemon', 'outputFilter'));
        set_error_handler(array('Daemon', 'errorHandler'));

        Daemon::checkSupports();

        Daemon::$initServerVar = $_SERVER;
        Daemon::$masters = new ThreadCollection;
        Daemon::$shm_wstate = Daemon::shmop_open(Daemon::$config->pidfile->value, Daemon::$shm_wstate_size, 'wstate');
        Daemon::openLogs();
    }


    /**
     * Is thing supported
     * @param int $what - Thing to check
     * @return bool
     * @static
     */
    public static function supported($what)
    {
        return isset(self::$support[$what]);
    }

    /**
     * Method to fill $support array
     * @return void
     * @static
     */
    private static function checkSupports()
    {
        if (is_callable('runkit_lint_file')) {
            self::$support[self::SUPPORT_RUNKIT_SANDBOX] = true;
        }

        if (is_callable('runkit_function_add')) {
            self::$support[self::SUPPORT_RUNKIT_MODIFY] = true;
        }

        if (is_callable('runkit_import')) {
            self::$support[self::SUPPORT_RUNKIT_IMPORT] = true;
        }

        if (
            self::supported(self::SUPPORT_RUNKIT_MODIFY)
            && ini_get('runkit.internal_override')
        ) {
            self::$support[self::SUPPORT_RUNKIT_INTERNAL_MODIFY] = true;
        }
    }

    /**
     * Check file syntax via runkit_lint_file if supported or via php -l
     * @param string $filename
     * @return bool
     * @static
     */
    public static function lintFile($filename)
    {
        if (!file_exists($filename)) {
            return false;
        }

        if (self::supported(self::SUPPORT_RUNKIT_SANDBOX)) {
            return runkit_lint_file($filename);
        }

        $cmd = 'php -l ' . escapeshellcmd($filename) . ' 2>&1';

        exec($cmd, $output, $retcode);

        return 0 === $retcode;
    }

    /**
     * It allows to run your simple web-apps in spawn-fcgi/php-fpm environment.
     * @return bool
     * @static
     */
    public static function compatRunEmul()
    {
//        not use
//        Daemon::$dir = realpath(__DIR__ . '/..');
        Daemon::$compatMode = true;
        Daemon::initSettings();

        $argv = isset($_SERVER['CMD_ARGV']) ? $_SERVER['CMD_ARGV'] : '';

        $args = Daemon_Bootstrap::getArgs($argv);

        if (isset($args[$k = 'configfile'])) {
            Daemon::$config[$k] = $args[$k];
        }

        if (!Daemon::$config->loadCmdLineArgs($args)) {
            $error = true;
        }
        if (
            isset(Daemon::$config->configfile->value)
            && !Daemon::loadConfig(Daemon::$config->configfile->value)
        ) {
            $error = true;
        }

        if (!isset(Daemon::$config->path->value)) {
            exit('\'path\' is not defined');
        }

        $appResolver = require Daemon::$config->path->value;
        $appResolver->init();

        $req = new stdClass();
        $req->attrs = new stdClass();
        $req->attrs->request = $_REQUEST;
        $req->attrs->get = $_GET;
        $req->attrs->post = $_REQUEST;
        $req->attrs->put = null;
        $req->attrs->cookie = $_REQUEST;
        $req->attrs->server = $_SERVER;
        $req->attrs->files = $_FILES;
        $req->attrs->session = isset($_SESSION) ? $_SESSION : null;
        $req->attrs->connId = 1;
        $req->attrs->trole = 'RESPONDER';
        $req->attrs->flags = 0;
        $req->attrs->id = 1;
        $req->attrs->params_done = true;
        $req->attrs->stdin_done = true;
        $req = $appResolver->getRequest($req);

        while (true) {
            $ret = $req->call();
            if ($ret === 1) {
                return;
            }
        }
    }

    /**
     * Load config-file
     * @param string $path - Path
     * @return bool
     * @static
     */
    public static function loadConfig($path)
    {
        $apaths = explode(';', $path);

        foreach ($apaths as $path) {
            if (is_file($p = realpath($path))) {

                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));

                if ($ext == 'conf') {
                    return Daemon::$config->loadFile($p);
                }
                Daemon::log('Config file \'' . $p . '\' has unsupported file extension.');
                return false;
            }
        }

        Daemon::log('Config file not found in \'' . $path . '\'.');
        return false;
    }

    /**
     * Open logs.
     * @return void
     * @static
     */
    public static function openLogs()
    {
        if (Daemon::$config->logging->value) {
            Daemon::$logPointer = fopen(Daemon::$config->logstorage->value, 'a');
            if (isset(Daemon::$config->group->value)) {
                chgrp(Daemon::$config->logstorage->value, Daemon::$config->group->value); // @TODO: rewrite to async I/O
            }
            if (isset(Daemon::$config->user->value)) {
                chown(Daemon::$config->logstorage->value, Daemon::$config->user->value); // @TODO: rewrite to async I/O
            }
            if ((Daemon::$process instanceof Daemon_WorkerThread) && FS::$supported) {
                FS::open(Daemon::$config->logstorage->value, 'a!', function ($file) {
                    Daemon::$logPointerAsync = $file;
                    if (!$file) {
                        return;
                    }
                });
            }
        } else {
            Daemon::$logPointer = null;
            Daemon::$logPointerAsync = null;
        }
    }

    /**
     * Get state of workers
     * @param Daemon_MasterThread $master
     * @return int[]
     * @static
     * TODO: get rid of magic numbers in status (use constants)
     */
    public static function getStateOfWorkers($master = null)
    {
        static $bufSize = 1024;
        $offset = 0;

        $stat = array(
            'idle' => 0,
            'busy' => 0,
            'alive' => 0,
            'shutdown' => 0,
            'preinit' => 0,
            'waitinit' => 0,
            'init' => 0,
        );

        $c = 0;

        while ($offset < Daemon::$shm_wstate_size) {
            $buf = shmop_read(Daemon::$shm_wstate, $offset, $bufSize);

            for ($i = 0; $i < $bufSize; ++$i) {
                $code = ord($buf[$i]);

                if ($code >= 100) {
                    // reloaded (shutdown)
                    $code -= 100;

                    if ($master !== null) {
                        $master->reloadWorker($offset + $i + 1);
                    }
                }

                if ($code === 0) {
                    break 2;
                } elseif ($code === 1) {
                    // idle
                    ++$stat['alive'];
                    ++$stat['idle'];
                } elseif ($code === 2) {
                    // busy
                    ++$stat['alive'];
                    ++$stat['busy'];
                } elseif ($code === 3) {
                    // shutdown
                    ++$stat['shutdown'];
                } elseif ($code === 4) {
                    // pre-init
                    ++$stat['alive'];
                    ++$stat['preinit'];
                    ++$stat['idle'];
                } elseif ($code === 5) {
                    // wait-init
                    ++$stat['alive'];
                    ++$stat['waitinit'];
                    ++$stat['idle'];
                } elseif ($code === 6) { // init
                    ++$stat['alive'];
                    ++$stat['init'];
                    ++$stat['idle'];
                }

                ++$c;
            }

            $offset += $bufSize;
        }

        return $stat;
    }

    /**
     * Opens segment of shared memory.
     * @param string $path - Path to file.
     * @param int $size - Size of segment.
     * @param string $name - Name of segment.
     * @param bool $create - Whether to create if it doesn't exist.
     * @return int - Resource ID.
     * @static
     */
    public static function shmop_open($path, $size, $name, $create = true)
    {
        if (
            $create
            && !touch($path)
        ) {
            Daemon::log('Couldn\'t touch IPC file \'' . $path . '\'.');
            exit(0);
        }

        if (($key = ftok($path, 't')) === FALSE) {
            Daemon::log('Couldn\'t ftok() IPC file \'' . $path . '\'.');
            exit(0);
        }

        if (!$create) {
            $shm = shmop_open($key, 'w', 0, 0);
        } else {
            $shm = @shmop_open($key, 'w', 0, 0);

            if ($shm) {
                shmop_delete($shm);
                shmop_close($shm);
            }

            $shm = shmop_open($key, 'c', 0755, $size);
        }

        if (!$shm) {
            Daemon::log('Couldn\'t open IPC-' . $name . ' shared memory segment (key=' . $key . ', size=' . $size . ', uid=' . posix_getuid() . ').');
            exit(0);
        }

        return $shm;
    }

    /**
     * Send message to log.
     * @param string - message
     * @return void
     * @static
     */
    public static function log()
    {
        $args = func_get_args();
        if (sizeof($args) == 1) {
            $msg = is_scalar($args[0]) ? $args[0] : Debug::dump($args[0]);
        } else {
            $msg = Debug::dump($args);
        }

        $mt = explode(' ', microtime());

        if (is_resource(STDERR)) {
            fwrite(STDERR, '[PHPD] ' . $msg . "\n");
        }
        $msg = '[' . date('D, j M Y H:i:s', $mt[1]) . '.' . sprintf('%06d', $mt[0] * 1000000) . ' ' . date('O') . '] ' . $msg . "\n";
        if (Daemon::$logPointerAsync) {
            Daemon::$logPointerAsync->write($msg);
        } elseif (Daemon::$logPointer) {
            fwrite(Daemon::$logPointer, $msg);
        }
    }

    /**
     * Spawn new master process.
     * @return int - pid thread
     * @static
     */
    public static function spawnMaster()
    {
        Daemon::$masters->push($thread = new Daemon_MasterThread);
        $thread->start();

        if (-1 === $thread->pid) {
            Daemon::log('could not start master');
            exit(0);
        }

        return $thread->pid;
    }

    /**
     * Calculates a difference between two dates.
     * @param int $st
     * @param int $fin
     * @return array - [seconds, minutes, hours, days, months, years]
     * @static
     */
    public static function date_period($st, $fin)
    {
        if (
            (is_int($st))
            || (ctype_digit($st))
        ) {
            $st = date('d-m-Y-H-i-s', $st);
        }

        $st = explode('-', $st);

        if (
            (is_int($fin))
            || (ctype_digit($fin))
        ) {
            $fin = date('d-m-Y-H-i-s', $fin);
        }

        $fin = explode('-', $fin);

        if (($seconds = $fin[5] - $st[5]) < 0) {
            $fin[4]--;
            $seconds += 60;
        }

        if (($minutes = $fin[4] - $st[4]) < 0) {
            $fin[3]--;
            $minutes += 60;
        }

        if (($hours = $fin[3] - $st[3]) < 0) {
            $fin[0]--;
            $hours += 24;
        }

        if (($days = $fin[0] - $st[0]) < 0) {
            $fin[1]--;
            $days += (int)date('t', mktime(1, 0, 0, $fin[1], $fin[0], $fin[2]));
        }

        if (($months = $fin[1] - $st[1]) < 0) {
            $fin[2]--;
            $months += 12;
        }

        $years = $fin[2] - $st[2];

        return array($seconds, $minutes, $hours, $days, $months, $years);
    }

    /**
     * Calculates a difference between two dates.
     * @param int $dateStart
     * @param int $dateFinish
     * @return string - Something like this: 1 year. 2 mon. 6 day. 4 hours. 21 min. 10 sec.
     * @static
     */
    public static function date_period_text($dateStart, $dateFinish)
    {
        $result = Daemon::date_period($dateStart, $dateFinish);

        $str = '';

        if ($result[5] > 0) {
            $str .= $result[5] . ' year. ';
        }

        if ($result[4] > 0) {
            $str .= $result[4] . ' mon. ';
        }

        if ($result[3] > 0) {
            $str .= $result[3] . ' day. ';
        }

        if ($result[2] > 0) {
            $str .= $result[2] . ' hour. ';
        }

        if ($result[1] > 0) {
            $str .= $result[1] . ' min. ';
        }

        if (
            $result[0] > 0
            || $str == ''
        ) {
            $str .= $result[0] . ' sec. ';
        }

        return rtrim($str);
    }
}