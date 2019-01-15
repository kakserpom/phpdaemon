<?php
namespace PHPDaemon\Applications\GibsonREST;

class Request extends \PHPDaemon\HTTPRequest\Generic
{

    protected $result;
    protected $cmd;
    protected $args;
    protected $performed = false;

    /*
     * Constructor.
     * @return void
     */
    public function init()
    {
        try {
            $this->header('Content-Type: text/plain');
            //$this->header('Content-Type: application/x-json');
        } catch (\Exception $e) {
        }
        if (!$this->importCmdArgs()) {
            return;
        }
        $this->sleep(5, true); // setting timeout 5 seconds */
        $this->onSessionStart(function () {
            $this->wakeup();
            if ($this->cmd === 'LOGIN') {
                if (sizeof($this->args) !== 2) {
                    $this->result = ['$err' => 'You must pass exactly 2 arguments.'];
                    $this->wakeup();
                    return;
                }
                if ((hash_equals($this->appInstance->config->username->value,
                    $this->args[0]) + hash_equals($this->appInstance->config->password->value,
                        $this->args[1])) < 2) {
                    $this->result = ['$err' => 'Wrong username and/or password.'];
                    return;
                }
                $this->attrs->session['logged'] = $this->appInstance->config->credver;
                $this->result = ['$ok' => 1];
                $this->wakeup();
                return;
            } elseif ($this->cmd === 'LOGOUT') {
                unset($this->attrs->session['logged']);
                $this->result = ['$ok' => 1];
                $this->wakeup();
                return;
            }
            if (!isset($this->attrs->session['logged']) || $this->attrs->session['logged'] < $this->appInstance->config->credver) {
                $this->result = ['$err' => 'You must be authenticated.'];
                $this->wakeup();
                return;
            }
        });
    }

    /*
     * Import command name and arguments from input
     * @return void
     */
    protected function importCmdArgs()
    {
        $this->cmd = static::getString($_GET['cmd']);
        if ($this->cmd === '') {
            $e = explode('/',
                isset($this->attrs->server['SUBPATH']) ? $this->attrs->server['SUBPATH'] : $this->attrs->server['DOCUMENT_URI']);
            $this->cmd = array_shift($e);
            $this->args = sizeof($e) ? array_map('urldecode', $e) : [];
        }
        if (!$this->appInstance->gibson->isCommand($this->cmd) && $this->cmd !== 'LOGIN' && $this->cmd !== 'LOGOUT') {
            $this->result = ['$err' => 'Unrecognized command'];
            return false;
        }
        return true;
    }

    /*
     * Import command name and arguments from input
     * @return void
     */

    /**
     * Called when request iterated.
     * @return integer Status.
     */
    public function run()
    {
        if (!$this->performed && $this->result === null) {
            $this->performed = true;
            $this->importCmdArgsFromPost();
            $this->performCommand();
            if ($this->result === null) {
                $this->sleep(5);
                return;
            }
        }
        echo json_encode($this->result);
    }

    /*
     * Performs command
     * @return void
     */

    protected function importCmdArgsFromPost()
    {
        if ($this->result === null) {
            foreach (static::getArray($_POST['args']) as $arg) {
                if (is_string($arg)) {
                    $this->args[] = $arg;
                }
            }
        }
    }

    protected function performCommand()
    {
        $args = $this->args;
        $args[] = function ($conn) {
            if (!$conn->isFinal()) {
                return;
            }
            $this->result = $conn->result;
            $this->wakeup();
        };
        $func = [$this->appInstance->gibson, $this->cmd];
        $func(...$args);
    }
}
