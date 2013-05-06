<?php
namespace PHPDaemon;

class IPCManagerWorkerConnection extends Connection {
	protected $timeout = null;
	protected $lowMark = 4; // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF; // initial value of the maximum amout of bytes in buffer
	const STATE_CONTENT = 1;
	protected $packetLength;

	public function onReady() {
		$this->sendPacket([
							  'op'       => 'start',
							  'pid'      => Daemon::$process->getPid(),
							  'workerId' => Daemon::$process->getId()
						  ]);
		parent::onReady();
	}

	protected function onPacket($p) {
		if ($p['op'] === 'spawnInstance') {
			$fullname = $p['appfullname'];
			$fullname = str_replace('-', ':', $fullname);
			if (strpos($fullname, ':') === false) {
				$fullname .= ':';
			}
			list($app, $name) = explode(':', $fullname, 2);
			Daemon::$appResolver->appInstantiate($app, $name, true);
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

			if ($app = Daemon::$appResolver->getInstanceByAppName($app, $name)) {
				$app->RPCall($p['method'], $p['args']);
			}
		}
	}

	public function sendPacket($p) {
		if ($p === null) {
			return;
		}
		$data = igbinary_serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
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
				$this->setWatermark($this->packetLength);
				return; // not ready yet
			}
			$this->setWatermark(4);
			$this->state = self::STATE_ROOT;
			$this->onPacket(igbinary_unserialize($packet));
		}
		goto start;
	}
}