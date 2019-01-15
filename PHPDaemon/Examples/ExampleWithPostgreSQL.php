<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;

/**
 * @package    Examples
 * @subpackage PostgreSQL
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleWithPostgreSQL extends \PHPDaemon\Core\AppInstance
{

    public $pgsql;

    /**
     * Constructor.
     * @return void
     */
    public function init()
    {
        $this->pgsql = \PHPDaemon\Clients\PostgreSQL\Pool::getInstance();
    }

    /**
     * Creates Request.
     * @param object Request.
     * @param object Upstream application instance.
     * @return ExampleWithPostgreSQLRequest Request.
     */
    public function beginRequest($req, $upstream)
    {
        return new ExampleWithPostgreSQLRequest($this, $upstream, $req);
    }
}

class ExampleWithPostgreSQLRequest extends Generic
{

    public $stime;
    public $queryResult;
    public $sql;
    public $runstate = 0;

    /**
     * Constructor.
     * @return void
     */
    public function init()
    {
        $this->appInstance->pgsql->getConnection(function ($sql) {
            if (!$sql->connected) { // failed to connect
                $this->wakeup(); // wake up the request immediately
                $sql->release();
                return;
            }
            $sql->query('SELECT 123 as integer, NULL as nul, \'test\' as string', function ($sql, $success) {
                $this->queryResult = $sql->resultRows; // save the result
                $this->wakeup(); // wake up the request immediately
                $sql->release();
            });
        });
        $this->sleep(5);
    }

    /**
     * Called when request iterated.
     * @return integer Status.
     */
    public function run()
    {
        try {
            $this->header('Content-Type: text/html');
        } catch (\Exception $e) {
        }
        ?>
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
            "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml">
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <title>Example with PostgreSQL</title>
        </head>
        <body>
        <?php

        if ($this->queryResult) {
            echo '<h1>It works! Be happy! ;-)</h1>Result of `SELECT 123 as integer, NULL as nul, \'test\' as string`: <pre>';
            var_dump($this->queryResult);
            echo '</pre>';
        } else {
            echo '<h1>Something went wrong! We have no result.</h1>';
        }

        echo '<br />Request (http) took: ' . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 6);

        ?>
        </body>
        </html>
        <?php

    }
}
