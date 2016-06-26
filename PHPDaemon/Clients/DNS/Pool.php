<?php
namespace PHPDaemon\Clients\DNS;

use PHPDaemon\Cache\CappedStorageHits;
use PHPDaemon\Core\ComplexJob;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Network\Client;

/**
 * @package    NetworkClients
 * @subpackage DNSClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends Client
{

    /**
     * @var array Record Types [code => "name", ...]
     */
    public static $type = [
        1 => 'A',
        2 => 'NS',
        3 => 'MD',
        4 => 'MF',
        5 => 'CNAME',
        6 => 'SOA',
        7 => 'MB',
        8 => 'MG',
        9 => 'MR',
        10 => 'RR',
        11 => 'WKS',
        12 => 'PTR',
        13 => 'HINFO',
        14 => 'MINFO',
        15 => 'MX',
        16 => 'TXT',
        17 => 'RP',
        18 => 'AFSDB',
        19 => 'X25',
        20 => 'ISDN',
        21 => 'RT',
        22 => 'NSAP',
        23 => 'NSAP-PTR',
        24 => 'SIG',
        25 => 'KEY',
        26 => 'PX',
        27 => 'GPOS',
        28 => 'AAAA',
        29 => 'LOC',
        30 => 'NXT',
        31 => 'EID',
        32 => 'NIMLOC',
        33 => 'SRV',
        34 => 'ATMA',
        35 => 'NAPTR',
        36 => 'KX',
        37 => 'CERT',
        38 => 'A6',
        39 => 'DNAME',
        40 => 'SINK',
        41 => 'OPT',
        42 => 'APL',
        43 => 'DS',
        44 => 'SSHFP',
        45 => 'IPSECKEY',
        46 => 'RRSIG',
        47 => 'NSEC',
        48 => 'DNSKEY',
        49 => 'DHCID',
        50 => 'NSEC3',
        51 => 'NSEC3PARAM',
        55 => 'HIP',
        99 => 'SPF',
        100 => 'UINFO',
        101 => 'UID',
        102 => 'GID',
        103 => 'UNSPEC',
        249 => 'TKEY',
        250 => 'TSIG',
        251 => 'IXFR',
        252 => 'AXFR',
        253 => 'MAILB',
        254 => 'MAILA',
        255 => 'ALL',
        32768 => 'TA',
        32769 => 'DLV',
    ];

    /**
     * @var array Hosts file parsed [hostname => [addr, ...], ...]
     */
    public $hosts = [];

    /**
     * @var \PHPDaemon\Core\ComplexJob Preloading ComplexJob
     */
    public $preloading;

    /**
     * @var CappedStorageHits Resolve cache
     */
    public $resolveCache;

    /**
     * @var array Classes [code => "class"]
     */
    public static $class = [
        1 => 'IN',
        3 => 'CH',
        255 => 'ANY',
    ];

    /**
     * @var array resolve.conf file parsed
     */
    public $nameServers = [];

    /**
     * Constructor
     */
    protected function init()
    {
        $this->resolveCache = new CappedStorageHits($this->config->resolvecachesize->value);
    }

    /**
     * Setting default config options
     * Overriden from NetworkClient::getConfigDefaults
     * @return array
     */
    protected function getConfigDefaults()
    {
        return [
            /* [integer] port */
            'port' => 53,

            /* [integer] resolvecachesize */
            'resolvecachesize' => 128,

            /* [string] Servers */
            'servers' => '',

            /* [string] hostsfile */
            'hostsfile' => '/etc/hosts',

            /* [string] resolvfile */
            'resolvfile' => '/etc/resolv.conf',

            /* [boolean] Expose? */
            'expose' => 1,
        ];
    }

    /**
     * Applies config
     * @return void
     */
    public function applyConfig()
    {
        parent::applyConfig();
        $pool = $this;
        if (!isset($this->preloading)) {
            $this->preloading = new ComplexJob();
        }
        $job = $this->preloading;
        $job->addJob('resolvfile', function ($jobname, $job) use ($pool) {
            FileSystem::readfile($pool->config->resolvfile->value, function ($file, $data) use ($pool, $job, $jobname) {
                if ($file) {
                    foreach (explode("\n", $data) as $line) {
                        $line = trim($line);
                        if ($line !== '' && $line[0] !== '#') {
                            preg_match('~nameserver ([^\r\n;]+)~i', $line, $m);
                            $pool->nameServers[] = $m[1];
                        }
                    }
                }
                $job->setResult($jobname);
            });
        });
        $job->addJob('hostsfile', function ($jobname, $job) use ($pool) {
            FileSystem::readfile($pool->config->hostsfile->value, function ($file, $data) use ($pool, $job, $jobname) {
                if ($file) {
                    preg_match_all('~^(\S+)\s+([^\r\n]+)\s*~m', $data, $m, PREG_SET_ORDER);
                    $pool->hosts = [];
                    foreach ($m as $h) {
                        $hosts = preg_split('~\s+~', $h[2]);
                        $ip = $h[1];
                        foreach ($hosts as $host) {
                            $host = rtrim($host, '.') . '.';
                            $pool->hosts[$host][] = $ip;
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
     * @param  string $hostname Hostname
     * @param  callable $cb Callback
     * @param  boolean $noncache Noncache?
     * @param  array $nameServers
     * @callback $cb ( array|string $addrs )
     * @return void
     */
    public function resolve($hostname, $cb, $noncache = false, $nameServers = [])
    {
        if (!$this->preloading->hasCompleted()) {
            $pool = $this;
            $this->preloading->addListener(function ($job) use ($hostname, $cb, $noncache, $pool, $nameServers) {
                $pool->resolve($hostname, $cb, $noncache, $nameServers);
            });
            return;
        }
        $hostname = rtrim($hostname, '.') . '.';
        if (isset($this->hosts[$hostname])) {
            $cb($this->hosts[$hostname]);
            return;
        }
        if (!$noncache && ($item = $this->resolveCache->get($hostname))) { // cache hit
            $ip = $item->getValue();
            if ($ip === null) { // operation in progress
                $item->addListener($cb);
            } else { // hit
                $cb($ip);
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
                    $cb(false);
                } else {
                    $pool->resolveCache->put($hostname, false, 5); // 5 - TTL of unsuccessful request
                }
                return;
            }
            if (!isset($response['A']) && !isset($response['AAAA'])) {
                $cb(false);
                return;
            }
            $addrs = [];
            $ttl = 0;
            if (isset($response['A'])) {
                foreach ($response['A'] as $r) {
                    $addrs[] = $r['ip'];
                    $ttl = $r['ttl'];
                }
            }
            if (isset($response['AAAA'])) {
                foreach ($response['AAAA'] as $r) {
                    $addrs[] = $r['ip'];
                    $ttl = $r['ttl'];
                }
            }
            if (sizeof($addrs) === 1) {
                $addrs = $addrs[0];
            }
            if ($noncache) {
                $cb($addrs);
            } else {
                $pool->resolveCache->put($hostname, $addrs, $ttl);
            }
        }, $noncache, $nameServers);
    }

    /**
     * Gets the host information
     * @param  string $hostname Hostname
     * @param  callable $cb Callback
     * @param  boolean $noncache Noncache?
     * @param  array $nameServers
     * @param  string $proto
     * @callback $cb ( )
     * @return void
     */
    public function get($hostname, $cb, $noncache = false, $nameServers = [], $proto = 'udp')
    {
        $pool = $this;
        if (!$nameServers) {
            $nameServers = $this->nameServers;
        }
        if (!$this->preloading->hasCompleted()) {
            $this->preloading->addListener(function ($job) use (
                $hostname,
                $cb,
                $noncache,
                $pool,
                $nameServers,
                $proto
            ) {
                $pool->get($hostname, $cb, $noncache, $nameServers, $proto);
            });
            return;
        }
        $nameServer = reset($nameServers);
        $isIpv6 = filter_var($nameServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if ($isIpv6) {
            $nameServer = '[' . $nameServer . ']';
        }
        $onGetConnection = function ($conn) use ($cb, $hostname, $nameServers, $noncache, $pool, $proto) {
            if (!$conn || !$conn->isConnected()) {
                if ($proto === 'udp') {
                    //Fail to  connect via udp, trying by tcp
                    $pool->get($hostname, $cb, $noncache, $nameServers, 'tcp');
                    return;
                }
                array_shift($nameServers);
                if (!$nameServers) {
                    //Totally fail to resolve name
                    $cb(false);
                } else {
                    //Fail connect to curr Ns, but we can try another ns
                    $pool->get($hostname, $cb, $noncache, $nameServers, 'udp');
                }
            } else {
                $conn->get(
                    $hostname,
                    function ($response) use ($hostname, $cb, $proto, $noncache, $nameServers, $pool) {
                        if ($response === false && $proto === 'udp') {
                            //Fail to  connect via udp, trying by tcp
                            $pool->get($hostname, $cb, $noncache, $nameServers, 'tcp');
                        } else {
                            call_user_func($cb, $response);
                        }
                    }
                );
            }
        };
        list($host, $type, $class) = explode(':', $hostname . '::', 3);
        if ($type === 'AXFR') {
            $proto = 'tcp';
        }
        $this->getConnection($proto . '://' . $nameServer, $onGetConnection);
        return;
    }
}
