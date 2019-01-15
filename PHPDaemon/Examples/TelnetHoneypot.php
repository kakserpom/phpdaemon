<?php
namespace PHPDaemon\Examples;

/**
 * @package    NetworkServers
 * @subpackage TelnetHoneypot
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class TelnetHoneypot extends \PHPDaemon\Network\Server
{
    public $connectionClass = '\PHPDaemon\Examples\TelnetHoneypotConnection';

    /**
     * Setting default config options
     * Overriden from ConnectionPool::getConfigDefaults
     * @return array|false
     */
    protected function getConfigDefaults()
    {
        return [
            // @todo add description strings
            'listen' => '0.0.0.0',
            'port' => 23,
        ];
    }
}

class TelnetHoneypotConnection extends \PHPDaemon\Network\Connection
{
    /**
     * Called when new data received.
     * @return void
     */
    public function onRead()
    {
        while (!is_null($line = $this->readline())) {
            $finish =
                (mb_orig_strpos($line, $s = "\xff\xf4\xff\xfd\x06") !== false)
                || (mb_orig_strpos($line, $s = "\xff\xec") !== false)
                || (mb_orig_strpos($line, $s = "\x03") !== false)
                || (mb_orig_strpos($line, $s = "\x04") !== false);

            $e = explode(' ', rtrim($line, "\r\n"), 2);

            $cmd = trim($e[0]);

            if ($cmd === 'ping') {
                $this->writeln('pong');
            } elseif (
                ($cmd === 'exit')
                || ($cmd === 'quit')
            ) {
                $this->finish();
            } else {
                $this->writeln('Unknown command "' . $cmd . '"');
            }

            if (
                (mb_orig_strlen($line) > 1024)
                || $finish
            ) {
                $this->finish();
            }
        }
    }

    /**
     * Called when the session finished
     * @return void
     */
    public function onFinish()
    {
        $this->writeln('Bye!');
    }
}
