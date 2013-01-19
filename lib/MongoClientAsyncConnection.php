<?php
class MongoClientAsyncConnection extends NetworkClientConnection {
	public $url;               // url
	public $user;              // Username
	public $password;          // Password
	public $dbname;            // Database name
	public $busy = false;      // Is this session busy?

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
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		$l = strlen($this->buf);

		if ($l < 16) {
			// we have not enough data yet
			return;
		}
		
		$h = unpack('Vlen/VreqId/VresponseTo/VopCode', binarySubstr($this->buf, 0, 16));
		$plen = (int) $h['len'];
		
		if ($plen > $l) {
			// we have not enough data yet
			return;
		} 
		
		if ($h['opCode'] === MongoClientAsync::OP_REPLY) {
			$r = unpack('Vflag/VcursorID1/VcursorID2/Voffset/Vlength', binarySubstr($this->buf, 16, 20));
					//Daemon::log(array($r,$h));
			$r['cursorId'] = binarySubstr($this->buf, 20, 8);
			$id = (int) $h['responseTo'];
			$flagBits = str_pad(strrev(decbin($r['flag'])), 8, '0', STR_PAD_LEFT);
			$cur = ($r['cursorId'] !== "\x00\x00\x00\x00\x00\x00\x00\x00"?'c' . $r['cursorId'] : 'r' . $h['responseTo']);

			if (
				isset($this->pool->requests[$id][2]) 
				&& ($this->pool->requests[$id][2] === false) 
				&& !isset($this->pool->cursors[$cur])
			) {
				$this->pool->cursors[$cur] = new MongoClientAsyncCursor($cur, $this->pool->requests[$id][0], $this);
				$this->pool->cursors[$cur]->failure = $flagBits[1] === '1';
				$this->pool->cursors[$cur]->await = $flagBits[3] === '1';
				$this->pool->cursors[$cur]->callback = $this->pool->requests[$id][1];
				$this->pool->cursors[$cur]->parseOplog = 
					isset($this->pool->requests[$id][3]) 
					&& $this->pool->requests[$id][3];
				$this->pool->cursors[$cur]->tailable = 
					isset($this->pool->requests[$id][4]) 
					&& $this->pool->requests[$id][4];
			}
			//Daemon::log(array(Debug::exportBytes($cur),get_Class($this->pool->cursors[$cur])));
			
			if (
				isset($this->pool->cursors[$cur]) 
				&& (
					($r['length'] === 0) 
					|| (binarySubstr($cur, 0, 1) === 'r')
				)
			) {

				if ($this->pool->cursors[$cur]->tailable) {
					if ($this->pool->cursors[$cur]->finished = ($flagBits[0] == '1')) {
						$this->pool->cursors[$cur]->destroy();
					}
				} else {
					$this->pool->cursors[$cur]->finished = true;
				}
			}
			
			$p = 36;			
			while ($p < $plen) {
				$dl = unpack('Vlen', binarySubstr($this->buf, $p, 4));
				$doc = bson_decode(binarySubstr($this->buf, $p, $dl['len']));

				if (
					isset($this->pool->cursors[$cur]) 
					&& @$this->pool->cursors[$cur]->parseOplog 
					&& isset($doc['ts'])
				) {
					$tsdata = unpack('Vsec/Vinc', binarySubstr($this->buf, $p + 1 + 4 + 3, 8));
					$doc['ts'] = $tsdata['sec'] . ' ' . $tsdata['inc'];
				}

				$this->pool->cursors[$cur]->items[] = $doc;
				$p += $dl['len'];
			}
			
			$this->setFree(true);
			
			if (
				isset($this->pool->requests[$id][2]) 
				&& $this->pool->requests[$id][2]
				&& $this->pool->requests[$id][1]
			) {
				call_user_func(
					$this->pool->requests[$id][1], 
					isset($this->pool->cursors[$cur]->items[0]) ? $this->pool->cursors[$cur]->items[0] : false
				);

				if (isset($this->pool->cursors[$cur])) {
					if ($this->pool->cursors[$cur] instanceof MongoClientAsyncCursor) {
						$this->pool->cursors[$cur]->destroy();
					} else {
						unset($this->pool->cursors[$cur]);
					}
				}
			} 
			elseif (isset($this->pool->cursors[$cur])) {
				call_user_func($this->pool->cursors[$cur]->callback, $this->pool->cursors[$cur]);
			}
			
			unset($this->pool->requests[$id]);
		}
		
		$this->buf = binarySubstr($this->buf, $plen);
		
		goto start;
	}
	
}