<?php

return new ExampleSandbox;

class ExampleSandbox extends AppInstance
{

    public $counter = 0;
    /* @method init
      @description Constructor.
      @return void
     */

    public function init()
    {
        $o = $this;
    }

    /* @method onReady
      @description Called when the worker is ready to go.
      @return void
     */

    public function onReady()
    {
        // Initialization.
    }

    /* @method onShutdown
      @description Called when application instance is going to shutdown.
      @return boolean Ready to shutdown?
     */

    public function onShutdown()
    {
        // Finalization.
        return TRUE;
    }

    /* @method beginRequest
      @description Creates Request.
      @param object Request.
      @param object Upstream application instance.
      @return object Request.
     */

    public function beginRequest($req, $upstream)
    {
        return new ExampleSandboxRequest($this, $upstream, $req);
    }

}

class ExampleSandboxRequest extends Request
{
    /* @method run
      @description Called when request iterated.
      @return integer Status.
     */

    public function run()
    {
        $stime = microtime(TRUE);
        $this->header('Content-Type: text/html; charset=utf-8');

        $options = array(
            'safe_mode' => TRUE,
            'open_basedir' => '/var/www/users/jdoe/',
            'allow_url_fopen' => 'false',
            'disable_functions' => 'exec,shell_exec,passthru,system',
            'disable_classes' => '',
            'output_handler' => array($this, 'out')
        );
        $sandbox = new Runkit_Sandbox($options);
        $sandbox->ini_set('html_errors', true);
        $sandbox->eval('echo "Hello World!";');
        return Request::DONE;
    }

}
