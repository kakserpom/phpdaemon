<?php
namespace PHPDaemon\Clients\GearmanClient;

use PHPDaemon\Network\ClientConnection;


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
     * Request codes
     *
     * @var array
     */
    private $requestCommandList = [
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

    /**
     * Response codes
     *
     * @var array
     */
    private $responseCommandList = [
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
     * Flag
     * @var bool
     */
    private $jobAwaitResult     = false;

    /**
     * Generate a random string token
     *
     * @param int $length How many characters do we want?
     * @param string $keySpaces A string of all possible characters  to select from
     *
     * @return string
     */
    private function generateUniqueToken($length = 10, $keySpaces = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {

        $half = intval($length / 2);
        $result = substr(str_shuffle($keySpaces), 0, $half) . substr(str_shuffle($keySpaces), 0, $length - $half);

        return $result;
    }

    /**
     * Called when new data received
     *
     * @return void
     */
    public function onRead() {

        if (($head = $this->readExact(static::HEADER_LENGTH)) === false) {
            return;
        }

        $head = unpack(static::HEADER_READ_FORMAT, $head);
        list($magic, $type, $size) = array_values($head);

        $type_array = $magic === static::MAGIC_RESPONSE ? $this->responseCommandList : $this->requestCommandList;

        $TYPE_CODE = NULL;

        foreach ($type_array as $key => $info) {

            if (intval($info[0]) === intval($type)) {
                $TYPE_CODE = $key;
                break;
            }
        }

        if (!$this->jobAwaitResult || 'WORK_COMPLETE' === $TYPE_CODE) {

            $body = $this->read($size);
            $argv = strlen($body) ? explode(static::ARGS_DELIMITER, $body) : [];

            $this->jobAwaitResult = false;
            $this->onResponse->executeOne($this, $argv);

            $this->checkFree();
            return ;
        }
    }

    /**
     * Function send ECHO
     *
     * @param $payload
     * @param callable|null $cb
     */
    public function sendEcho ($payload, callable $cb = null) {
        $this->sendCommand('ECHO_REQ', $payload, $cb);
    }

    /**
     * Function run task and wait result in callback
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function runJob($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique =  $this->generateUniqueToken();
        }

        $this->jobAwaitResult = true;
        $this->sendCommand('SUBMIT_JOB', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task and wait result in callback
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doNormal($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task in background
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doBackground($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB_BG', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task with high prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param stirng $unique
     */
    public function doHigh($function_name, $payload, callable $resultCallback, $unique = null) {

        if (!is_string($unique)) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB_HIGH', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task in background with high prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doHighBackground($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB_HIGH_BG', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task with low priority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doLow($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB_LOW', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Function run task in background with low priority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doLowBackground($function_name, $payload, callable $resultCallback, $unique = null) {

        if ($unique) {
            $unique = $this->generateUniqueToken();
        }

        $this->sendCommand('SUBMIT_JOB_LOW_BG', [$function_name, $unique,  $payload], $resultCallback);
    }

    /**
     * Get job status
     * 
     * @param $job_handle Job handle that was given in JOB_CREATED packet.
     *
     */
    public function doStatus($job_handle, callable $resultCallback) {
        $this->sendCommand('GET_STATUS', [$job_handle], $resultCallback);
    }

    /**
     * Function set settings for current connection
     * Available settings
     * 'exceptions' - Forward WORK_EXCEPTION packets to the client.
     *
     * @url http://gearman.org/protocol/
     *
     *
     * @param int $option_name
     * @param callable $doneCallback
     */
    public function setConnectionOption($option_name, callable $doneCallback) {
        $this->sendCommand('OPTION_RES', [$option_name], $doneCallback);
    }

    /**
     * Low level commands sender
     *
     * @param $commandName
     * @param $payload
     * @param $doneCallback callable|null $doneCallback
     */
    private function sendCommand($commandName, $payload, callable $doneCallback = null) {

        $payload = (array) $payload;
        $payload = array_map(function($item){ return !is_scalar($item) ? serialize($item) : $item; }, $payload);
        $payload = implode(static::ARGS_DELIMITER, $payload);

        $len = strlen($payload);

        list($command_id) = $this->requestCommandList[$commandName];
        $this->onResponse->push($doneCallback);

        $sendData = pack(static::HEADER_WRITE_FORMAT, static::MAGIC_REQUEST, $command_id, $len);
        $sendData .= $payload;

        $this->write($sendData);
    }
}