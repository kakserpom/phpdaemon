<?php
class MongoClientAsyncConnection extends NetworkClientConnection {
	public $url;               // url
	public $user;              // Username
	public $password;          // Password
	public $dbname;            // Database name
	public $busy = false;      // Is this session busy?
	protected $lowMark  = 16;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	private $curPacket;
	const STATE_PACKET = 1;

	public function onReady() {
		$conn = $this;
		if ($conn->user !== NULL) {
			$this->pool->getNonce(array(
				'dbname' => $conn->dbname), 
				function($result) use ($conn) {
					$conn->appInstance->auth(
						array(
							'user'     => $conn->user, 
							'password' => $conn->password, 
							'nonce'    => $result['nonce'], 
							'dbname'   => $conn->dbname, 
						), 
						function($result) use ($conn) {
							if (!$result['ok']) {
								Daemon::log('MongoClient: authentication error with ' . $conn->url . ': ' . $result['errmsg']);
							}
						}, 
						$conn
					);
				}, $conn
			);
		}
		parent::onReady();
	}
	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			if (false === ($hdr = $this->readExact(16))) {
				return; // we do not have a header
			}
			$this->curPacket = unpack('Vlen/VreqId/VresponseTo/VopCode', $hdr);
			$this->curPacket['blen'] = $this->curPacket['len'] - 16;
			$this->setWatermark($this->curPacket['blen']);
			$this->state = self::STATE_PACKET;
		}
		if ($this->state === self::STATE_PACKET) {
			if (false === ($pct = $this->readExact($this->curPacket['blen']))) {
				return; //we do not have a whole packet
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(16);
			if ($this->curPacket['opCode'] === MongoClientAsync::OP_REPLY) {
				$r = unpack('Vflag/VcursorID1/VcursorID2/Voffset/Vlength', binarySubstr($pct, 0, 20));
				$r['cursorId'] = binarySubstr($pct, 20, 8);
				$id = (int) $this->curPacket['responseTo'];
				if (isset($this->pool->requests[$id])) {
					$req =& $this->pool->requests[$id];
				} else {
					$req = false;
				}
				$flagBits = str_pad(strrev(decbin($r['flag'])), 8, '0', STR_PAD_LEFT);
				$curId = ($r['cursorId'] !== "\x00\x00\x00\x00\x00\x00\x00\x00"?'c' . $r['cursorId'] : 'r' . $this->curPacket['responseTo']);

				if ($req && ($req[2] === false) && !isset($this->pool->cursors[$curId])) {
					$cur = new MongoClientAsyncCursor($curId, $req[0], $this);
					$this->pool->cursors[$curId] = $cur;
					$cur->failure = $flagBits[1] === '1';
					$cur->await = $flagBits[3] === '1';
					$cur->callback = $req[1];
					$cur->parseOplog = isset($req[3]) && $req[3];
					$cur->tailable = isset($req[4]) && $req[4];
				} else {
					$cur = isset($this->pool->cursors[$curId]) ? $this->pool->cursors[$curId] : false;
				}
				//Daemon::log(array(Debug::exportBytes($curId),get_Class($this->pool->cursors[$curId])));		
				if ($cur && (($r['length'] === 0) || (binarySubstr($curId, 0, 1) === 'r'))) {
					if ($cur->tailable) {
						if ($cur->finished = ($flagBits[0] == '1')) {
							$cur->destroy();
						}
					} else {
						$cur->finished = true;
					}
				}
			
				$p = 20;			
				$items = [];
				while ($p < $this->curPacket['blen']) {
					$dl = unpack('Vlen', binarySubstr($pct, $p, 4));
					$doc = bson_decode(binarySubstr($pct, $p, $dl['len']));

					if ($cur) {
						if ($cur->parseOplog && isset($doc['ts'])) {
							$tsdata = unpack('Vsec/Vinc', binarySubstr($pct, $p + 1 + 4 + 3, 8));
							$doc['ts'] = $tsdata['sec'] . ' ' . $tsdata['inc'];
						}
						$cur->items = $items;
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
						if ($cur instanceof MongoClientAsyncCursor) {
							$cur->destroy();
						} else {
							unset($this->pool->cursors[$curId]);
						}
					}
				} 
				elseif ($cur) {
					call_user_func($cur->callback, $cur);
				}
				unset($this->pool->requests[$id], $req);
			}
		}
		goto start;
	}
}
