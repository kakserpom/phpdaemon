<?php
namespace PHPDaemon\Clients\GearmanClient;

use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Crypt;


/**
 * @package NetworkClients
 * @subpackage GearmanClient
 * @protocol http://gearman.org/protocol/
 *
 * @interface http://php.net/manual/ru/class.gearmanclient.php
 *
 * @author Popov Gennadiy <me@westtrade.tk>
 */
class  Connection extends ClientConnection {

    /**
     * Magic code for request
     */
    const MAGIC_REQUEST         = "\0REQ";

    /**
     * Magic code for response
     */
    const MAGIC_RESPONSE        = "\0RES";

    /*
     * Byte length of header
     */
    const HEADER_LENGTH         = 12;

    /**
     * Header binary format
     */
    const HEADER_WRITE_FORMAT   = "a4NN";

    /**
     * Header read format
     */
    const HEADER_READ_FORMAT    = "a4magic/Ntype/Nsize";

    /**
     * Delimeter for function arguments
     */
    const ARGS_DELIMITER        = "\0";



    /**
     * Request codes
     *
     * @var array
     */
    protected static $requestCommandList = [
        'CAN_DO' => 1,
        'CANT_DO' => 2,
        'RESET_ABILITIES' => 3,
        'PRE_SLEEP' => 4,
        'SUBMIT_JOB' => 7,
        'GRAB_JOB' => 9,
        'WORK_STATUS' => 12,
        'WORK_COMPLETE' => 13,
        'WORK_FAIL' => 14,
        'GET_STATUS' => 15,
        'ECHO_REQ' => 16,
        'SUBMIT_JOB_BG' => 18,
        'SUBMIT_JOB_HIGH' => 21,
        'SET_CLIENT_ID' => 22,
        'CAN_DO_TIMEOUT' => 23,
        'ALL_YOURS' => 24,
        'WORK_EXCEPTION' => 25,
        'OPTION_REQ' => 26,
        'OPTION_RES' => 27,
        'WORK_DATA' => 28,
        'WORK_WARNING' => 29,
        'GRAB_JOB_UNIQ' => 30,
        'SUBMIT_JOB_HIGH_BG' => 32,
        'SUBMIT_JOB_LOW' => 33,
        'SUBMIT_JOB_LOW_BG' => 34,
        'SUBMIT_JOB_SCHED' => 35,
        'SUBMIT_JOB_EPOCH' => 36,
    ];
    protected static $requestCommandListFlipped;

    /**
     * Response codes
     *
     * @var array
     */
    protected static $responseCommandList = [
        'NOOP' => 6,
        'JOB_CREATED' => 8,
        'NO_JOB' => 10,
        'JOB_ASSIGN' => 11,
        'WORK_STATUS' => 12,
        'WORK_COMPLETE' => 13,
        'WORK_FAIL' => 14,
        'ECHO_RES' => 17,
        'ERROR' => 19,
        'STATUS_RES' => 20,
        'WORK_EXCEPTION' => 25,
        'OPTION_RES' => 27,
        'WORK_WARNING' => 29,
        'JOB_ASSIGN_UNIQ' => 31,
    ];

    protected static $responseCommandListFlipped;

    /**
     * @var mixed
     */
    public $response;

    /**
     * @var string
     */
    public $responseType;

    /**
     * @var string
     */
    public $responseCommand;

    /**
     * Called when new data received
     *
     * @return void
     */
    public function onRead()
    {
        if (($head = $this->lookExact(static::HEADER_LENGTH)) === false) {
            return;
        }

        list($magic, $typeInt, $size) = unpack(static::HEADER_READ_FORMAT, $head);

        if ($this->getInputLength() < static::HEADER_LENGTH + $size) {
            return;
        }

        $this->drain(static::HEADER_LENGTH);
        $pct = $this->read($size);

        if ($magic === static::MAGIC_RESPONSE) {
            $this->responseType = static::responseCommandListFlipped[$typeInt];
            $this->response = explode(static::ARGS_DELIMITER, $pct);
            $this->onResponse->executeOne($this);
            $this->responseType = null;
            $this->responseCommand = null;
            $this->responseType = null;
            $this->checkFree();
            return;
        } else {
            $type = static::$requestCommandListFlipped[$typeInt];
            // @TODO
        }
    }
    
    /**
     * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
     * @return void
     */
    public function onReady()
    {
        if (static::$requestCommandListFlipped === null) {
            static::$requestCommandListFlipped = array_flip(static::$requestCommandList);
        }
        if (static::$responseCommandListFlipped === null) {
            static::$responseCommandListFlipped = array_flip(static::$responseCommandList);
        }
        parent::onReady();
    }


    /**
     * Function send ECHO
     *
     * @param $payload
     * @param callable|null $cb
     */
    public function sendEcho ($payload, $cb = null)
    {
        $this->sendCommand('ECHO_REQ', $payload, $cb);
    }

    /**
     * Function run task and wait result in callback
     *
     * @param $params
     * @param callable $cb = null
     * @param boolean $unique
     */
    public function submitJob($params, $cb = null)
    {
        $closure = function () use (&$params, $cb)
        {
            $this->sendCommand('SUBMIT_JOB'
                . (isset($params['pri']) ? '_ ' . strtoupper($params['pri']) : '')
                . (isset($params['bg']) && $params['bg'] ? '_BG' : ''),
                [$params['function'], $params['unique'], $params['payload']],
                $cb
            );
        };
        if (isset($params['unique'])) {
            $closure();
        } else {
            Crypt::randomString(10, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', function($random) use ($closure) {
                $params['unique'] = $random;
                $closure();
            });
        }
    }

    /**
     * Get job status
     * 
     * @param mixed $jobHandle Job handle that was given in JOB_CREATED packet.
     * @param callable $cb = null
     *
     */
    public function getStatus($jobHandle, $cb = null) {
        $this->sendCommand('GET_STATUS', [$jobHandle], $cb);
    }

    /**
     * Function set settings for current connection
     * Available settings
     * 'exceptions' - Forward WORK_EXCEPTION packets to the client.
     *
     * @url http://gearman.org/protocol/
     *
     *
     * @param int $optionName
     * @param callable $cb = null
     */
    public function setConnectionOption($optionName, $cb = null) {
        $this->sendCommand('OPTION_RES', [$optionName], $cb);
    }

    /**
     * Send a command
     *
     * @param $commandName
     * @param $payload
     * @param callable $cb = null
     */
    public function sendCommand($commandName, $payload, $cb = null) {

        $pct = implode(
            static::ARGS_DELIMITER,
            array_map(function($item){ return !is_scalar($item) ? serialize($item) : $item; }, (array) $payload)
        );
        $this->onResponse->push($cb);
        $this->write(pack(
            static::HEADER_WRITE_FORMAT,
            static::MAGIC_REQUEST, $this->requestCommandList[$commandName], mb_orig_strlen($pct)));
        $this->write($pct);
    }
}