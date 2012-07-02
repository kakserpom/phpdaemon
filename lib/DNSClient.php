<?php
/**
 * @package NetworkClients
 * @subpackage DNSClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class DNSClient extends NetworkClient {
	public static $type = array(
 		1 => 'A', 2 => 'NS',  3 => 'MD', 4 => 'MF', 5 => 'CNAME',
 		6 => 'SOA', 7 => 'MB', 8 => 'MG', 9 => 'MR', 10 => 'RR',
 		11 => 'WKS', 12 => 'PTR', 13 => 'HINFO', 14 => 'MINFO',
 		15 => 'MX', 16 => 'TXT', 17 => 'RP', 18 => 'AFSDB',
 		19 => 'X25', 20 => 'ISDN',
 		21 => 'RT', 22 => 'NSAP', 23 => 'NSAP-PTR', 24 => 'SIG',
 		25 => 'KEY', 26 => 'PX', 27 => 'GPOS', 28 => 'AAAA', 29 => 'LOC',
 		30 => 'NXT', 31 => 'EID', 32 => 'NIMLOC', 33 => 'SRV',
 		34 => 'ATMA', 35 => 'NAPTR', 36 => 'KX', 37 => 'CERT', 38 => 'A6',
 		39 => 'DNAME', 40 => 'SINK', 41 => 'OPT', 42 => 'APL', 43 => 'DS',
 		44 => 'SSHFP', 45 => 'IPSECKEY', 46 => 'RRSIG', 47 => 'NSEC',
 		48 => 'DNSKEY', 49 => 'DHCID', 50 => 'NSEC3', 51 => 'NSEC3PARAM',
 		55 => 'HIP', 99 => 'SPF', 100 => 'UINFO', 101 => 'UID', 102 => 'GID',
 		103 => 'UNSPEC', 249 => 'TKEY', 250 => 'TSIG', 251 => 'IXFR',
 		252 => 'AXFR', 253 => 'MAILB', 254 => 'MAILA', 255 => 'ALL',
 		32768 => 'TA', 32769 => 'DLV',
	);

	public static $class = array(
 		1 => 'IN',
 		3 => 'CH',
 		255 => 'ANY',
	);

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'defaultport' => 53,
			'server' => '127.0.0.1',
			'expose' => 1,
		);
	}
	public function resolve($hostname, $cb) {
		$this->get($hostname, function ($response) use ($cb) {
			if (!isset($response['A'])) {
				call_user_func($cb, false);
				return;
			}
			mt_srand(Daemon::$process->pid);
			$r = $response['A'][array_rand($response['A'])];
			call_user_func($cb, $r['ip']);
			mt_srand();
		});
	}
	public function get($domain, $cb) {
		$conn = $this->getConnection();
		if (!$conn) {
			return false;
		}
 		$conn->onResponse->push($cb);
		$conn->setFree(false);
		$e = explode(':', $domain, 3);
		$domain = $e[0];
		$qtype = isset($e[1]) ? $e[1] : 'A';
		$qclass = isset($e[2]) ? $e[2] : 'IN';
		$QD = array();
		$qtypeInt = array_search($qtype, DNSClient::$type, true);
		$qclassInt = array_search($qclass, DNSClient::$class, true);
		if (($qtypeInt === false) || ($qclassInt === false)) {
			call_user_func($cb, false);
			return;
		}
		$q =	Binary::labels($domain) .  // domain
				Binary::word($qtypeInt) . 
				Binary::word($qclassInt);
		$QD[] = $q;
		$packet = 
			Binary::word(++$conn->seq) . // Query ID
			Binary::bitmap2bytes(
				'0' . // QR = 0
				'0000' . // OPCODE = 0000 (standard query)
				'0' . // AA = 0
				'0' . // TC = 0
				'1' . // RD = 1

				'0' . // RA = 0, 
				'000' . // reserved
				'0000' // RCODE
			, 2) . 
			Binary::word(sizeof($QD)) . // QDCOUNT
			Binary::word(0) . // ANCOUNT
			Binary::word(0) . // NSCOUNT
			Binary::word(0) . // ARCOUNT
			implode('', $QD);
		$conn->write(Binary::word(strlen($packet)) . $packet);

	}
}
class DNSClientConnection extends NetworkClientConnection {
	protected $lowMark = 2;
	public $seq = 0;
	public $keepalive = false;


	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		$length = Binary::bytes2int(binarySubstr($this->buf, 0, 2));
		if ($length > strlen($this->buf) + 2) {
			return; // not enough data yet
		
		}
		$packet = binarySubstr($this->buf, 2, $length);
		$this->buf = binarySubstr($this->buf, $length + 2);

		$id = Binary::getWord($packet);
		$bitmap = Binary::getBitmap(Binary::getByte($packet)) . Binary::getBitmap(Binary::getByte($packet));
		$qr = (int) $bitmap[0];
		$this->response['opcode'] = bindec(substr($bitmap, 1, 4));
		$this->response['aa'] = (int) $bitmap[5];
		$tc = (int) $bitmap[6];
		$rd = (int) $bitmap[7];
		$ra = (int) $bitmap[8];
		$z = bindec(substr($bitmap, 9, 3));
		$this->response['qdcount']= Binary::getWord($packet);
		$this->response['ancount'] = Binary::getWord($packet);
		$this->response['nscount'] = Binary::getWord($packet);
		$this->response['arcount'] = Binary::getWord($packet);
		$this->response = array();
		$domain = Binary::parseLabels($packet);
		while (strlen($packet) > 0) {
			$qtypeInt = Binary::getWord($packet);
			$qtype = isset(DNSClient::$type[$qtypeInt]) ? DNSClient::$type[$qtypeInt] : 'UNK(' . $qtypeInt . ')';
			$qclassInt = Binary::getWord($packet);
			$qclass = isset(DNSClient::$class[$qclassInt]) ? DNSClient::$class[$qclassInt] : 'UNK(' . $qclassInt . ')';

			if (binarySubstr($packet, 0, 2) === "\xc0\x0c") {
				$packet = binarySubstr($packet, 2);
				continue;
			}
			$ttl = Binary::getDWord($packet);
			$rdlength = Binary::getWord($packet);
			$rdata = binarySubstr($packet, 0, $rdlength);
			$packet = binarySubstr($packet, $rdlength);
			$record = array(
				'type' => $qtype,
				'domain' => $domain,
				'ttl' => $ttl,
				'class' => $qclass,
			);
			if ($qtype === 'A') {
				$record['ip'] = inet_ntop($rdata);
			}
			elseif ($qtype === 'NS') {
				$record['ns'] = Binary::parseLabels($rdata);
			}
			if (!isset($this->response[$qtype])) {
				$this->response[$qtype] = array();
			}
			$this->response[$qtype][] = $record;
			if (binarySubstr($packet, 0, 2) === "\xc0\x0c") {
				$packet = binarySubstr($packet, 2);
				continue;
			}
		}
		$this->requestFinished();
	}

	public function requestFinished() {
		$cb = $this->onResponse->isEmpty() ? null : $this->onResponse->shift();
		if ($cb) {
			call_user_func($cb, $this->response);
		}
		if (!$this->keepalive) {
			$this->finish();
		} else {
			$this->checkFree();
		}
	}
}
