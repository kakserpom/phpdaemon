<?php
namespace PHPDaemon\IPCManager;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Network\Connection;

class WorkerConnection extends Connection {
	/** @var null */
	protected $timeout = null;
	/** @var int */
	protected $lowMark = 4; // initial value of the minimal amout of bytes in buffer
	/** @var int */
	protected $highMark = 0xFFFF; // initial value of the maximum amout of bytes in buffer
	/**
	 * @TODO DESCR
	 */
	const STATE_CONTENT = 1;
	/** @var */
	protected $packetLength;

	/**
	 * @TODO DESCR
	 */
	public function onReady() {
		$this->sendPacket([
							  'op'       => 'start',
							  'pid'      => Daemon::$process->getPid(),
							  'workerId' => Daemon::$process->getId()
						  ]);
		parent::onReady();
	}

	/**
	 * @param $p
	 */
	protected function onPacket($p) {
		if ($p['op'] === 'spawnInstance') {
			$fullname = $p['appfullname'];
			$fullname = str_replace('-', ':', $fullname);
			if (strpos($fullname, ':') === false) {
				$fullname .= ':';
			}
			list($app, $name) = explode(':', $fullname, 2);
			Daemon::$appResolver->getInstance($app, $name, true, true);
		}
		elseif ($p['op'] === 'importFile') {
			if (!Daemon::$config->autoreimport->value) {
				Daemon::$process->gracefulRestart();
				return;
			}
			$path = $p['path'];
			TImer::add(function ($event) use ($path) {
				if (Daemon::supported(Daemon::SUPPORT_RUNKIT_IMPORT)) {
					//Daemon::log('--start runkit_import('.$path.')');
					runkit_import($path, RUNKIT_IMPORT_FUNCTIONS | RUNKIT_IMPORT_CLASSES | RUNKIT_IMPORT_OVERRIDE);
					//Daemon::log('--end runkit_import('.$path.')');
				}
				else {
					$this->appInstance->log('Cannot import \'' . $path . '\': runkit_import is not callable.');
				}

				$event->finish();
			}, 5);
		}
		elseif ($p['op'] === 'call') {
			if (strpos($p['appfullname'], ':') === false) {
				$p['appfullname'] .= ':';
			}
			list($app, $name) = explode(':', $p['appfullname'], 2);
			if ($app = Daemon::$appResolver->getInstance($app, $name)) {
				$app->RPCall($p['method'], $p['args']);
			}
		}
	}

	/**
	 * @TODO DESCR
	 * @param $p
	 */
	public function sendPacket($p) {
		if ($p === null) {
			return;
		}
		$data = \igbinary_serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
	 * @TODO DESCR
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			if (false === ($r = $this->readExact(4))) {
				return; // not ready yet
			}
			$u                  = unpack('N', $r);
			$this->packetLength = $u[1];
			$this->state        = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			if (false === ($packet = $this->readExact($this->packetLength))) {
				$this->setWatermark($this->packetLength, $this->packetLength);
				return; // not ready yet
			}
			$this->setWatermark(4, 0xFFFF);
			$this->state = self::STATE_ROOT;
			$this->onPacket(\igbinary_unserialize($packet));
		}
		goto start;
	}
}