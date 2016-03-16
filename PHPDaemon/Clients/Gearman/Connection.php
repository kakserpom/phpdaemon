<?php
namespace PHPDaemon\Clients\Gearman;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Utils\Binary;

/**
 * Class Connection асинхронный класс для работы с Gearmanом
 *
 * TODO Сделать проверку данных
 *
 * @package PHPDaemon\YouServer\YOUGearman
 */
class  Connection extends ClientConnection {

    /**
     * Request codes
     *
     * @var array
     */
    private $requestCommandList = array(
        'CAN_DO' => array (1),
        'CANT_DO' => array (2),
        'RESET_ABILITIES' => array (3),
        'PRE_SLEEP' => array (4),
        'SUBMIT_JOB' => array (7),
        'GRAB_JOB' => array (9),
        'WORK_STATUS' => array (12),
        'WORK_COMPLETE' => array (13),
        'WORK_FAIL' => array (14),
        'GET_STATUS' => array (15),
        'ECHO_REQ' => array (16),
        'SUBMIT_JOB_BG' => array (18),
        'SUBMIT_JOB_HIGH' => array (21),
        'SET_CLIENT_ID' => array (22),
        'CAN_DO_TIMEOUT' => array (23),
        'ALL_YOURS' => array (24),
        'WORK_EXCEPTION' => array (25),
        'OPTION_REQ' => array (26),
        'OPTION_RES' => array (27),
        'WORK_DATA' => array (28),
        'WORK_WARNING' => array (29),
        'GRAB_JOB_UNIQ' => array (30),
        'SUBMIT_JOB_HIGH_BG' => array (32),
        'SUBMIT_JOB_LOW' => array (33),
        'SUBMIT_JOB_LOW_BG' => array (34),
        'SUBMIT_JOB_SCHED' => array (35),
        'SUBMIT_JOB_EPOCH' => array (36),
    );

    /**
     * Response codes
     *
     * @var array
     */
    private $responseCommandList = array(
        'NOOP' => array (6),
        'JOB_CREATED' => array (8),
        'NO_JOB' => array (10),
        'JOB_ASSIGN' => array (11),
        'WORK_STATUS' => array (12),
        'WORK_COMPLETE' => array (13),
        'WORK_FAIL' => array (14),
        'ECHO_RES' => array (17),
        'ERROR' => array (19),
        'STATUS_RES' => array (20),
        'WORK_EXCEPTION' => array (25),
        'OPTION_RES' => array (27),
        'WORK_WARNING' => array (29),
        'JOB_ASSIGN_UNIQ' => array (31)
    );

    const MAGIC_REQUEST         = "\0REQ";

    const MAGIC_RESPONSE        = "\0RES";

    const HEADER_LENGTH         = 12;

    const HEADER_WRITE_FORMAT   = "a4NN";

    const HEADER_READ_FORMAT    = "a4magic/Ntype/Nsize";

    const ARGS_DELIMITER        = "\0";


    private $jobAwaitResult = false;

    /**
     * Called when new data received
     * 
     * @return void
     */
    public function onRead() {

        $head = $this->read($this::HEADER_LENGTH);
        $head = unpack($this::HEADER_READ_FORMAT, $head);
        list($magic, $type, $size) = array_values($head);

        $type_array = $magic === $this::MAGIC_RESPONSE ? $this->responseCommandList : $this->requestCommandList;
        $TYPE_CODE = NULL;

        foreach ($type_array as $key => $info) {
            if (intval($info[0]) === intval($type)) {
                $TYPE_CODE = $key;
                break;
            }
        }
        $this->log($head, $TYPE_CODE);

        if (!$this->jobAwaitResult || 'WORK_COMPLETE' === $TYPE_CODE) {
            $body = $this->read($size);
            $argv = strlen($body) ? explode($this::ARGS_DELIMITER, $body) : [];

            $this->drain($this::HEADER_LENGTH + $size);


            /*
                TODO Сделать проверку на количество значений присланных в ответе в некоторых случаях с учетом входных значений
                TODO Обработка ERROR
            */
            $this->jobAwaitResult = false;
            $this->onResponse->executeOne($this, $argv);
            $this->checkFree();
            //$this->close();
            return ;
        }

        $this->drain($this::HEADER_LENGTH + $size);
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
     * Function run task and whait result in callback
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function runJob($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->jobAwaitResult = true;

        $this->sendCommand("SUBMIT_JOB", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task and whait result in callback
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doNormal($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task in background
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doBackground($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB_BG", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task with high prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doHigh($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB_HIGH", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task in background with high prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doHighBackground($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB_HIGH_BG", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task with low prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doLow($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB_LOW", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Function run task in background with low prority
     *
     * @param $function_name
     * @param $payload
     * @param null $context
     * @param null $unique
     */
    public function doLowBackground($function_name, $payload, $unique = null) {

        if ($unique) {
            $unique = uuid_create();
        }

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("SUBMIT_JOB_LOW_BG", [$function_name, $unique,  $payload], $cb);
    }

    /**
     * Функция исполняет удаленно таск
     * @param $job_handle Job handle that was given in JOB_CREATED packet.
     *
     */
    public function doStatus($job_handle) {

        $cb = func_get_arg(func_num_args() - 1);
        $this->sendCommand("GET_STATUS", [$job_handle], $cb);
    }

    /**
     * Function set settings for current connection
     * Available settings
     * "exceptions" - Forward WORK_EXCEPTION packets to the client.
     *
     * @url http://gearman.org/protocol/
     *
     *
     * @param int $option_name
     * @param callable $doneCallback
     */
    public function setConnectionOption($option_name, callable $doneCallback) {
        $this->sendCommand("OPTION_RES", [$option_name], $doneCallback);
    }


    /**
     * Low level commands sender
     *
     * @param $commandName
     * @param $payload
     * @param callable|null $doneCallback
     */
    private function sendCommand($commandName, $payload, callable $doneCallback = null) {

        $payload = (array) $payload;
        $payload = array_map(function($item){ return !is_scalar($item) ? serialize($item) : $item; }, $payload);
        $payload = implode($this::ARGS_DELIMITER, $payload);

        $len = strlen($payload);

        list($command_id) = $this->requestCommandList[$commandName];
        $this->onResponse->push($doneCallback);

        $sendData = pack($this::HEADER_WRITE_FORMAT, "\0REQ", $command_id, $len);
        $sendData .= $payload;

        $this->write($sendData);
    }
}