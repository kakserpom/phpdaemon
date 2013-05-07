<?php
namespace PHPDaemon;

class IPCManagerMasterPoolConnection extends Connection {
	public $instancesCount = [];
	protected $timeout = null;
	protected $lowMark = 4; // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF; // initial value of the maximum amout of bytes in buffer
	protected $workerId;
	const STATE_CONTENT = 1;
	protected $packetLength;

	protected function onPacket($p) {
		if (!is_array($p)) {
			return;
		}
		//Daemon::log(Debug::dump($p));;
		if ($p['op'] === 'start') {
			$this->workerId                       = $p['workerId'];
			$this->pool->workers[$this->workerId] = $this;
			$this->pool->appInstance->updatedWorkers();
		}
		elseif ($p['op'] === 'broadcastCall') {
			$p['op'] = 'call';
			foreach ($this->pool->workers as $worker) {
				$worker->sendPacket($p);
			}
		}
		elseif ($p['op'] === 'directCall') {
			$p['op'] = 'call';
			if (!isset($this->pool->workers[$p['workerId']])) {
				Daemon::$process->log('directCall(). not sent.');
				return;
			}
			$this->pool->workers[$p['workerId']]->sendPacket($p);
		}
		elseif ($p['op'] === 'singleCall') {
			$p['op'] = 'call';
			$sent    = false;
			foreach ($this->pool->workers as $worker) {
				$worker->sendPacket($p);
				$sent = true;
				break;
			}
			if (!$sent) {
				Daemon::$process->log('singleCall(). not sent.');
			}
		}
		elseif ($p['op'] === 'addIncludedFiles') {
			foreach ($p['files'] as $file) {
				Daemon::$process->fileWatcher->addWatch($file, $this->workerId);
			}
		}
	}

	public function onFinish() {
		unset($this->pool->workers[$this->workerId]);
		$this->pool->appInstance->updatedWorkers();
	}

	public function sendPacket($p) {
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