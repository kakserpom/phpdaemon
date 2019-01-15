<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;

class ExampleICMPRequest extends Generic
{
/**
 * Constructor.
 * @return void
 */
public function init()
{
    $req = $this;

    $job = $this->job = new \PHPDaemon\Core\ComplexJob(function () use ($req) { // called when job is done

        $req->wakeup(); // wake up the request immediately

    });

    $job('pingjob', function ($name, $job) use ($req) { // registering job named 'pingjob'

        \PHPDaemon\Clients\ICMP\Pool::getInstance()->sendPing('8.8.8.8', function ($latency) use ($name, $job) {
            $job->setResult($name, $latency);
        });
    });

    $job(); // let the fun begin

    $this->sleep(5, true); // setting timeout
}

/**
 * Called when request iterated.
 * @return integer Status.
 */
public function run()
{
$this->header('Content-Type: text/html');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Ping 8.8.8.8</title>
</head>
<body>
<h1>Latency to 8.8.8.8:</h1>
<?php echo round($this->job->getResult('pingjob'), 4) * 1000;
?> ms.
</body>
</html><?php

}
}
