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

	public $hosts = array();
	public $preloading;
	public $resolveCache;

	public function init() {
		$this->resolveCache = new CappedCacheStorageHits($this->config->resolvecachesize->value);
	}

	public static $class = array(
 		1 => 'IN',
 		3 => 'CH',
 		255 => 'ANY',
	);

	public $ns;

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'port' => 53,
			'resolvecachesize' => 128,
			'servers' => '',
			'hostsfile' => '/etc/hosts',
			'resolvfile' => '/etc/resolv.conf',
			'expose' => 1,
		);
	}

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
						$pool->addServer('dns://[udp:' . $s . ']');
						//$pool->addServer('dns://[' . $s . ']');
					}
				}
				$job->setResult($jobname);
			});
		});
		$job->addJob('hostsfile', function ($jobname, $job) use ($pool) {
			FS::readfile($pool->config->hostsfile->value, function($file, $data) use ($pool, $job, $jobname) {
				if ($file) {
					preg_match_all('~^(\S+)\s+([^\r\n]+)\s*~m', $data, $m, PREG_SET_ORDER);
					$pool->hosts = array();
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
			srand(Daemon::$process->pid);
			$r = $response['A'][rand(0, sizeof($response['A']) - 1)];
			srand();
			if ($noncache) {
				call_user_func($cb, $r['ip']);
			} else {
				$pool->resolveCache->put($hostname, $r['ip'], $r['ttl']);
			}
		});
	}
	public function get($hostname, $cb, $noncache = false) {
		if (!$this->preloading->hasCompleted()) {
			$pool = $this;
			$this->preloading->addListener(function ($job) use ($hostname, $cb, $noncache, $pool) {
				$pool->get($hostname, $cb, $noncache);
			});
			return;
		}
		$this->getConnectionByKey($hostname, function($conn) use ($cb, $hostname) {
			if (!$conn->connected) {
				call_user_func($cb, false);
				return false;
			}
			$conn->onResponse->push($cb);
			$conn->setFree(false);
			$e = explode(':', $hostname, 3);
			$hostname = $e[0];
			$qtype = isset($e[1]) ? $e[1] : 'A';
			$qclass = isset($e[2]) ? $e[2] : 'IN';
			$QD = array();
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
			if ($conn->type === 'udp') {
				$conn->write($packet);
			} else {
				$conn->write(Binary::word(strlen($packet)) . $packet);
			}
		});
	}
}
class DNSClientConnection extends NetworkClientConnection {
	protected $lowMark = 2;
	public $seq = 0;
	public $keepalive = true;

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		start:
		$l = strlen($this->buf);
		if ($l < 2) {
			return; // not enough data yet
		}
		if ($this->type === 'udp') {
			$packet = $this->buf;
			$this->buf = '';
		} else {
			$length = Binary::bytes2int(binarySubstr($this->buf, 0, 2));
			if ($length > $l + 2) {
				return; // not enough data yet
			}
			$packet = binarySubstr($this->buf, 2, $length);
			$this->buf = binarySubstr($this->buf, $length + 2);
		}

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
		$hostname = Binary::parseLabels($packet);
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
				'domain' => $hostname,
				'ttl' => $ttl,
				'class' => $qclass,
			);
			if ($qtype === 'A') {
				if ($rdata === "\x00") {
					$record['ip'] = false;
					$record['ttl'] = 5;
					$packet = '';
					break;
				} else {
					$record['ip'] = inet_ntop($rdata);
				}
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
		$this->onResponse->executeOne($this->response);
		if (!$this->keepalive) {
			$this->finish();
			return;
		} else {
			$this->checkFree();
		}
		goto start;
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
