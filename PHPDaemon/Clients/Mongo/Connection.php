<?php
namespace PHPDaemon\Clients\Mongo;

use PHPDaemon\Clients\Mongo\Cursor;
use PHPDaemon\Clients\Mongo\Pool;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;

class Connection extends ClientConnection
{
	/** @var */
	public $url; // url
	/** @var */
	public $user; // Username
	/** @var */
	public $password; // Password
	/** @var */
	public $dbname; // Database name
	/** @var int */
	protected $lowMark = 16; // initial value of the minimal amout of bytes in buffer
	/** @var int */
	protected $highMark = 0xFFFFFF; // initial value of the maximum amout of bytes in buffer
	/** @var */
	protected $hdr;
	/**
	 * @TODO DESCR
	 */
	const STATE_PACKET = 1;
	/** @var array */
	public $cursors = []; // Active cursors
	/** @var array */
	public $requests = []; // Pending requests
	/** @var int */
	public $lastReqId = 0; // ID of the last request

	/**
	 * @TODO DESCR
	 */
	public function onReady() {
		if ($this->user === null) {
			$this->connected = true;
		}
		if ($this->connected) {
			parent::onReady();
			return;
		}
		$this->dbname = $this->path;
		$this->pool->getNonce(['dbname' => $this->dbname],
			function ($result) {
				if (isset($result['$err'])) {
					Daemon::log('MongoClient: getNonce() error with '.$this->url.': '.$result['$err']);
					$this->finish();
				}
				$this->pool->auth([
						'user'     => $this->user,
						'password' => $this->password,
						'nonce'    => $result['nonce'],
						'dbname'   => $this->dbname,
					],
					function ($result) {
						if (!isset($result['ok']) || !$result['ok']) {
							Daemon::log('MongoClient: authentication error with ' . $this->url . ': ' . $result['errmsg']);
							$this->finish();
							return;
						}
						$this->connected = true;
						$this->onReady();
					}, $this);
			}, $this
		);
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->freed) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			if (false === ($hdr = $this->readExact(16))) {
				return; // we do not have a header
			}
			$this->hdr         = unpack('Vlen/VreqId/VresponseTo/VopCode', $hdr);
			$this->hdr['plen'] = $this->hdr['len'] - 16;
			$this->setWatermark($this->hdr['plen'], $this->hdr['plen']);
			$this->state = self::STATE_PACKET;
		}
		if ($this->state === self::STATE_PACKET)
		{
			if (false === ($pct = $this->readExact($this->hdr['plen'])))
			{
				return; //we do not have a whole packet
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(16, 0xFFFFFF);
			if ($this->hdr['opCode'] === Pool::OP_REPLY) {
				$r             = unpack('Vflag/VcursorID1/VcursorID2/Voffset/Vlength', binarySubstr($pct, 0, 20));
				$r['cursorId'] = binarySubstr($pct, 4, 8);
				$id            = (int)$this->hdr['responseTo'];
				if (isset($this->requests[$id])) {
					$req = $this->requests[$id];
				}
				else {
					$req = false;
				}
				$flagBits = str_pad(strrev(decbin($r['flag'])), 8, '0', STR_PAD_LEFT);
				$curId    = ($r['cursorId'] !== "\x00\x00\x00\x00\x00\x00\x00\x00" ? 'c'.$r['cursorId'] : 'r'.$this->hdr['responseTo']);

				if ($req && isset($req[2]) && ($req[2]===false) && !isset($this->cursors[$curId])) {
					$cur                   = new Cursor($curId, $req[0], $this);
					$this->cursors[$curId] = $cur;
					$cur->failure          = $flagBits[1] === '1';
					$cur->await            = $flagBits[3] === '1';
					$cur->callback         = $req[1];
					$cur->parseOplog       = isset($req[3]) && $req[3];
					$cur->tailable         = isset($req[4]) && $req[4];
				}
				else {
					$cur = isset($this->cursors[$curId]) ? $this->cursors[$curId] : false;
				}
				if ($cur && (($r['length']===0) || (binarySubstr($curId, 0, 1) === 'r'))) {
					if ($cur->tailable) {
						if ($cur->finished = ($flagBits[0] == '1')) {
							$cur->destroy();
						}
					}
					else {
						$cur->finished = true;
					}
				}

				$p     = 20;
				$items = [];
				while ($p < $this->hdr['plen']) {
					$dl  = unpack('Vlen', binarySubstr($pct, $p, 4));
					$doc = bson_decode(binarySubstr($pct, $p, $dl['len']));

					if ($cur) {
						if ($cur->parseOplog && isset($doc['ts'])) {
							$tsdata    = unpack('Vsec/Vinc', binarySubstr($pct, $p + 8, 8));
							$doc['ts'] = $tsdata['sec'].' '.$tsdata['inc'];
						}
						$cur->items[] = $doc;
					}
					else {
						$items[] = $doc;
					}
					$p += $dl['len'];
				}
				$this->setFree(true);
				if (isset($req[2]) && $req[2] && $req[1]) {
					call_user_func(
						$req[1],
						sizeof($items) ? $items[0] : false
					);

					if ($cur) {
						if ($cur instanceof Cursor) {
							$cur->destroy();
						}
						else {
							unset($this->cursors[$curId]);
						}
					}
				}
				elseif ($cur) {
					call_user_func($cur->callback, $cur);
				}
				unset($this->requests[$id], $req);
			}
		}
		goto start;
	}
}
