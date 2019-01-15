<?php
namespace PHPDaemon\IPCManager;

use PHPDaemon\Config;
use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Thread;

class IPCManager extends AppInstance
{
    /** @var */
    public $pool;
    /** @var */
    public $conn;
    /** @var */
    public $socketurl;

    /**
     * Setting default config options
     * Overriden from AppInstance::getConfigDefaults
     * @return array|bool
     */
    protected function getConfigDefaults()
    {
        return [
            // listen to
            'mastersocket' => 'unix:///tmp/phpDaemon-ipc-%x.sock',
        ];
    }

    /**
     * Constructor.
     * @return void
     */
    public function init()
    {
        $this->socketurl = sprintf($this->config->mastersocket->value,
            crc32(Daemon::$config->pidfile->value . "\x00" . Daemon::$config->user->value . "\x00" . Daemon::$config->group->value));
        if (Daemon::$process instanceof Thread\IPC) {
            $this->pool = MasterPool::getInstance(['listen' => $this->socketurl]);
            $this->pool->appInstance = $this;
            $this->pool->onReady();
        }
    }

    public function getSocketUrl()
    {
        return $this->socketurl;
    }

    /**
     * @TODO DESCR
     */
    public function updatedWorkers()
    {
        $perWorker = 1;
        $instancesCount = [];
        foreach (Daemon::$config as $name => $section) {
            if ((!$section instanceof Config\Section) || !isset($section->limitinstances)) {
                continue;
            }
            $instancesCount[$name] = 0;
        }
        foreach ($this->pool->workers as $worker) {
            foreach ($worker->instancesCount as $k => $v) {
                if (!isset($instancesCount[$k])) {
                    unset($worker->instancesCount[$k]);
                    continue;
                }
                $instancesCount[$k] += $v;
            }
        }
        foreach ($instancesCount as $name => $num) {
            $v = Daemon::$config->{$name}->limitinstances->value - $num;
            foreach ($this->pool->workers as $worker) {
                if ($v <= 0) {
                    break;
                }
                if ((isset($worker->instancesCount[$name])) && ($worker->instancesCount[$name] < $perWorker)) {
                    continue;
                }
                if (!isset($worker->instancesCount[$name])) {
                    $worker->instancesCount[$name] = 1;
                } else {
                    ++$worker->instancesCount[$name];
                }
                $worker->sendPacket(['op' => 'spawnInstance', 'appfullname' => $name]);
                --$v;
            }
        }
    }

    /**
     * Called when application instance is going to shutdown.
     * @param bool $graceful
     * @return boolean Ready to shutdown?
     */
    public function onShutdown($graceful = false)
    {
        if ($this->pool) {
            return $this->pool->onShutdown();
        }
        return true;
    }

    /**
     * @TODO DESCR
     * @param $workerId
     * @param $path
     * @return bool
     */
    public function importFile($workerId, $path)
    {
        if (!isset($this->pool->workers[$workerId])) {
            return false;
        }
        $worker = $this->pool->workers[$workerId];
        $worker->sendPacket(['op' => 'importFile', 'path' => $path]);
        return true;
    }

    /**
     * @TODO DESCR
     */
    public function ensureConnection()
    {
        $this->sendPacket('');
    }

    /**
     * @TODO DESCR
     * @param $packet
     */
    public function sendPacket($packet = null)
    {
        if ($this->conn && $this->conn->isConnected()) {
            $this->conn->sendPacket($packet);
            return;
        }

        $cb = function ($conn) use ($packet) {
            $conn->sendPacket($packet);
        };
        if (!$this->conn) {
            $this->conn = new WorkerConnection(null, null, null);
            $this->conn->connect($this->socketurl);
        }
        $this->conn->onConnected($cb);
    }

    /**
     * @TODO DESCR
     * @param $appInstance
     * @param $method
     * @param array $args
     * @param callable $cb
     */
    public function sendBroadcastCall($appInstance, $method, $args = [], $cb = null)
    {
        $this->sendPacket([
            'op' => 'broadcastCall',
            'appfullname' => $appInstance,
            'method' => $method,
            'args' => $args,
        ]);
    }

    /**
     * @TODO DESCR
     * @param $appInstance
     * @param $method
     * @param array $args
     * @param callable $cb
     */
    public function sendSingleCall($appInstance, $method, $args = [], $cb = null)
    {
        $this->sendPacket([
            'op' => 'singleCall',
            'appfullname' => $appInstance,
            'method' => $method,
            'args' => $args,
        ]);
    }

    /**
     * @TODO DESCR
     * @param $workerId
     * @param $appInstance
     * @param $method
     * @param array $args
     * @param callable $cb
     */
    public function sendDirectCall($workerId, $appInstance, $method, $args = [], $cb = null)
    {
        $this->sendPacket([
            'op' => 'directCall',
            'appfullname' => $appInstance,
            'method' => $method,
            'args' => $args,
            'workerId' => $workerId,
        ]);
    }
}
