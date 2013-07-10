<?php
namespace PHPDaemon\Clients\Gibson;


use PHPDaemon\Core\Daemon;

class Pool extends \PHPDaemon\Network\Client {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Default servers
			 * @var string|array
			 */
			'servers'        => 'tcp://127.0.0.1',

			/**
			 * Default port
			 * @var integer
			 */
			'port'           => 10128,

			/**
			 * Maximum connections per server
			 * @var integer
			 */
			'maxconnperserv' => 32
		];
	}


    public function __call($name, $args) {
        $opcode = array_search($name, $this->opCodes);
        if($opcode!==false){
            Daemon::log('DO COMMAND '.$name);
            $onResponse = null;
            if (($e = end($args)) && (is_array($e) || is_object($e)) && is_callable($e)) {
                $onResponse = array_pop($args);
            }

            $data = implode(' ',$args);
            $qLen = sizeof($data)+2;
            $r = pack('ls',$qLen,$opcode).$data;
            $this->requestByServer($server = null, $r, $onResponse);
        }
    }


    protected  $opCodes = array(
        1=>'set',
        'ttl', 'get', 'del', 'inc', 'dec', 'lock', 'unlock', 'mset', 'mttl', 'mget', 'mdel',
        'minc', 'mdec', 'mlock', 'munlock', 'count', 'stats', 'ping', 'sizeof', 'msizeof', 'encof'
         , 0xFF=>'end'
    );

}
