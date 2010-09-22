<?php
return new ExampleWithPostgreSQL;

class ExampleWithPostgreSQL extends AppInstance
{
    /* @method beginRequest
      @description Creates Request.
      @param object Request.
      @param object Upstream application instance.
      @return object Request.
     */

    public function beginRequest($req, $upstream)
    {
        return new ExampleWithPostgreSQLRequest($this, $upstream, $req);
    }

}

class ExampleWithPostgreSQLRequest extends Request
{

    public $stime;
    public $queryResult;
    public $sql;
    public $runstate = 0;
    /* @method init
      @description Constructor.
      @return void
     */

    public function init()
    {
        $this->stime = microtime(TRUE);
        $sqlclient = Daemon::$appResolver->getInstanceByAppName('PostgreSQLClient');
        if ($sqlclient && ($this->sql = $sqlclient->getConnection())) {
            $this->sql->context = $this;
            $this->sql->onConnected(function($sql, $success) {
                        if (!$success) {
                            return;
                        }
                        $sql->query('SELECT 123 as integer, NULL as nul, \'test\' as string', function($sql, $success) {
                                    $sql->context->queryResult = $sql->resultRows; // save the result
                                    $sql->context->wakeup(); // wake up the request immediately
                                });
                    });
        }
    }

    /* @method run
      @description Called when request iterated.
      @return integer Status.
     */

    public function run()
    {
        if (!$this->queryResult && ($this->runstate++ === 0)) {
            $this->sleep(5);
        } // sleep for 5 seconds or untill wake up
        try {
            $this->header('Content-Type: text/html; charset=utf-8');
        } catch (Exception $e) {
            
        }
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
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
        echo '<br />Request (http) took: ' . round(microtime(TRUE) - $this->stime, 6);
?>
            </body>
        </html>
<?php
        return Request::DONE;
    }

}
