<?php
/**
 * @package NetworkClients
 * @subpackage DNSClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class DNSClient extends NetworkClient {
	/**
	 * Record Types 
	 * @var hash [code => "name", ...]
	 */	
	public static $type = [
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
	];

	/**
	 * Hosts file parsed
	 * @var hash [hostname => [addr, ...], ...]
	 */	
	public $hosts = [];

	/**
	 * Preloading ComplexJob
	 * @var ComplexJob
	 */	
	public $preloading;

	/**
	 * Resolve cache
	 * @var CappedCacheStorageHits
	 */
	public $resolveCache;

	/**
	 * Classes
	 * @var hash [code => "class"]
	 */
	public static $class = [
 		1 => 'IN',
 		3 => 'CH',
 		255 => 'ANY',
	];

	/**
	 * Constructor
	 * @return object
	 */	
	protected function init() {
		$this->resolveCache = new CappedCacheStorageHits($this->config->resolvecachesize->value);
	}

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'port' => 53,
			'resolvecachesize' => 128,
			'servers' => '',
			'hostsfile' => '/etc/hosts',
			'resolvfile' => '/etc/resolv.conf',
			'expose' => 1,
		];
	}

	/**
	 * Applies config
	 * @return void
	 */
	public function applyConfig() {
		parent::applyConfig();
		$pool = $this;
		if (!isset($this->preloading)) {
			$this->preloading = new ComplexJob;
		}
		$job = $this->preloading;
		$job->addJob('resolvfile', function ($jobname, $job) use ($pool) {
			FS::readfile($pool->config->resolvfile->value, function($file, $data) use ($pool, $job, $jobname) {
				if ($file) {
					preg_match_all('~nameserver ([^\r\n;]+)~', $data, $m);
					foreach ($m[1] as $s) {
						$pool->addServer('udp://' . $s);
						//$pool->addServer('tcp://' . $s);
					}
				}
				$job->setResult($jobname);
			});
		});
		$job->addJob('hostsfile', function ($jobname, $job) use ($pool) {
			FS::readfile($pool->config->hostsfile->value, function($file, $data) use ($pool, $job, $jobname) {
				if ($file) {
					preg_match_all('~^(\S+)\s+([^\r\n]+)\s*~m', $data, $m, PREG_SET_ORDER);
					$pool->hosts = [];
					foreach ($m as $h) {
						$hosts = preg_split('~\s+~', $h[2]);
						$ip = $h[1];
						foreach ($hosts as $host) {
							$host = rtrim($host, '.') . '.';
							$pool->hosts[$host] = $ip;
						}
					}
				}
				$job->setResult($jobname);
			});
		});
		$job();
	}

	/**
	 * Resolves the host
	 * @param string Hostname
	 * @param callable Callback
	 * @param [boolean Noncache?]
	 * @return void
	 */
	public function resolve($hostname, $cb, $noncache = false) {
		if (!$this->preloading->hasCompleted()) {
			$pool = $this;
			$this->preloading->addListener(function ($job) use ($hostname, $cb, $noncache, $pool) {
				$pool->resolve($hostname, $cb, $noncache);
			});
			return;
		}
		$hostname = rtrim($hostname, '.') . '.';
		if (isset($this->hosts[$hostname])) {
			call_user_func($cb, $this->hosts[$hostname]);
			return;
		}
		if (!$noncache && ($item = $this->resolveCache->get($hostname))) { // cache hit
			$ip = $item->getValue();
			if ($ip === null) { // operation in progress
				$item->addListener($cb);
			} else { // hit
				call_user_func($cb, $ip);
			}
			return;
		} elseif (!$noncache) {
			$item = $this->resolveCache->put($hostname, null);
			$item->addListener($cb);
		}
		$pool = $this;
		$this->get($hostname, function ($response) use ($cb, $noncache, $hostname, $pool) {
			if (!isset($response['A'])) {
				if ($noncache) {
					call_user_func($cb, false);
				} else {
					$pool->resolveCache->put($hostname, false, 5); // 5 - TTL of unsuccessful request
				}
				return;
			}
			if (!isset($response['A'])) {
				call_user_func($cb, false);
				return;
			}
			$addrs = [];
			$ttl = 0;
			foreach ($response['A'] as $r) {
				$addrs[] = $r['ip'];
				$ttl = $r['ttl'];
			}
			if (sizeof($addrs) === 1) {
				$addrs = $addrs[0];
			}
			if ($noncache) {
				call_user_func($cb, $addrs);
			} else {
				$pool->resolveCache->put($hostname, $addrs, $ttl);
			}
		});
	}

	/**
	 * Gets the host information
	 * @param string Hostname
	 * @param callable Callback
	 * @param [boolean Noncache?]
	 * @return void
	 */
	public function get($hostname, $cb, $noncache = false) {
		if (!$this->preloading->hasCompleted()) {
			$pool = $this;
			$this->preloading->addListener(function ($job) use ($hostname, $cb, $noncache, $pool) {
				$pool->get($hostname, $cb, $noncache);
			});
			return null;
		}
		$this->getConnectionByKey($hostname, function($conn) use ($cb, $hostname) {
			if (!$conn || !$conn->isConnected()) {
				call_user_func($cb, false);
			} else {
				$conn->get($hostname, $cb);
			}
		});
		return null;
	}
}
class DNSClientConnection extends NetworkClientConnection {

	/**
	 * Sequence
	 * @var integer
	 */
	protected $seq = 0;

	/**
	 * Keepalive?
	 * @var boolean
	 */
	protected $keepalive = true;

	/**
	 * Response
	 * @var hash
	 */
	public $response = [];

	const STATE_PACKET = 1;

	/**
	 * Current packet sie
	 * @var boolean
	 */
	protected $pctSize = 0;

	/**
	 * Default low mark. Minimum number of bytes in buffer.
	 * @var integer
	 */	
	protected $lowMark = 2;

	/**
	 * Default high mark. Maximum number of bytes in buffer.
	 * @var integer
	 */
	protected $highMark = 512;

	/**
	 * Called when new UDP packet received.
	 * @return void
	 */
	public function onUdpPacket($pct) {
		$orig = $pct;
		$this->response = [];
		/*$id = */Binary::getWord($pct);
		$bitmap = Binary::getBitmap(Binary::getByte($pct)) . Binary::getBitmap(Binary::getByte($pct));
		//$qr = (int) $bitmap[0];
		$opcode = bindec(substr($bitmap, 1, 4));
		//$aa = (int) $bitmap[5];
		//$tc = (int) $bitmap[6];
		//$rd = (int) $bitmap[7];
		//$ra = (int) $bitmap[8];
		//$z = bindec(substr($bitmap, 9, 3));
		//$rcode = bindec(substr($bitmap, 12));
		$qdcount = Binary::getWord($pct);
		$ancount = Binary::getWord($pct);
		$nscount = Binary::getWord($pct);
		$arcount = Binary::getWord($pct);
		for ($i = 0; $i < $qdcount; ++$i) {
			$name = Binary::parseLabels($pct, $orig);
			$typeInt = Binary::getWord($pct);
			$type = isset(DNSClient::$type[$typeInt]) ? DNSClient::$type[$typeInt] : 'UNK(' . $typeInt . ')';
			$classInt = Binary::getWord($pct);
			$class = isset(DNSClient::$class[$classInt]) ? DNSClient::$class[$classInt] : 'UNK(' . $classInt . ')';
			if (!isset($this->response[$type])) {
				$this->response[$type] = [];
			}
			$record = [
				'name' => $name,
				'type' => $type,
				'class' => $class,
			];
			$this->response['query'][] = $record;
		}
		$getResRecord = function(&$pct) use ($orig) {
			$name = Binary::parseLabels($pct, $orig);
			$typeInt = Binary::getWord($pct);
			$type = isset(DNSClient::$type[$typeInt]) ? DNSClient::$type[$typeInt] : 'UNK(' . $typeInt . ')';
			$classInt = Binary::getWord($pct);
			$class = isset(DNSClient::$class[$classInt]) ? DNSClient::$class[$classInt] : 'UNK(' . $classInt . ')';
			$ttl = Binary::getDWord($pct);
			$length = Binary::getWord($pct);
			$data = binarySubstr($pct, 0, $length);
			$pct = binarySubstr($pct, $length);

			$record = [
				'name' => $name,
				'type' => $type,
				'class' => $class,
				'ttl' => $ttl,
			];

			if ($type === 'A') {
				if ($data === "\x00") {
					$record['ip'] = false;
					$record['ttl'] = 5;
				} else {
					$record['ip'] = inet_ntop($data);
				}
			}
			elseif ($type === 'NS') {
				$record['ns'] = Binary::parseLabels($data);
			}
			elseif ($type === 'CNAME') {
				$record['cname'] = Binary::parseLabels($data, $orig);
			}

			return $record;
		};
		for ($i = 0; $i < $ancount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		for ($i = 0; $i < $nscount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		for ($i = 0; $i < $arcount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		$this->onResponse->executeOne($this->response);
		if (!$this->keepalive) {
			$this->finish();
			return;
		} else {
			$this->checkFree();
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->type === 'udp') {
			$this->onUdpPacket($this->read($this->highMark));
		}
		if ($this->state === self::STATE_ROOT) {
			if (false === ($hdr = $this->readExact(2))) {
				return; // not enough data
			}
			$this->pctSize = Binary::bytes2int($hdr, true);
			$this->setWatermark($this->pctSize);
			$this->state = self::STATE_PACKET;
		}
		if ($this->state === self::STATE_PACKET) {
			if (false === ($pct = $this->readExact($this->pctSize))) {
				return; // not enough data
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(2);
			$this->onUdpPacket($pct);
		}
		goto start;
	}

	/**
	 * Gets the host information
	 * @param string Hostname
	 * @param callable Callback
	 * @return void
	 */
	public function get($hostname, $cb) {
		$this->onResponse->push($cb);
		$this->setFree(false);
		$e = explode(':', $hostname, 3);
		$hostname = $e[0];
		$qtype = isset($e[1]) ? $e[1] : 'A';
		$qclass = isset($e[2]) ? $e[2] : 'IN';
		$QD = [];
		$qtypeInt = array_search($qtype, DNSClient::$type, true);
		$qclassInt = array_search($qclass, DNSClient::$class, true);
		if (($qtypeInt === false) || ($qclassInt === false)) {
			call_user_func($cb, false);
			return;
		}
		$q =	Binary::labels($hostname) .  // domain
				Binary::word($qtypeInt) . 
				Binary::word($qclassInt);
		$QD[] = $q;
		$packet = 
			Binary::word(++$this->seq) . // Query ID
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
		if ($this->type === 'udp') {
			$this->write($packet);
		} else {
			$this->write(Binary::word(strlen($packet)) . $packet);
		}
	}

	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		$this->onResponse->executeAll(false);
		parent::onFinish();
	}
}
