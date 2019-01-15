<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Example extends \PHPDaemon\Core\AppInstance
{

    public $counter = 0;

    /**
     * Constructor.
     * @return void
     */
    public function init()
    {
    }

    /**
     * Called when the worker is ready to go.
     * @return void
     */
    public function onReady()
    {
        // Initialization.
    }

    /**
     * Called when application instance is going to shutdown.
     * @return boolean Ready to shutdown?
     */
    public function onShutdown($graceful = false)
    {
        // Finalization.
        return true;
    }

    /**
     * Creates Request.
     * @param object Request.
     * @param object Upstream application instance.
     * @return ExampleRequest Request.
     */
    public function beginRequest($req, $upstream)
    {
        return new ExampleRequest($this, $upstream, $req);
    }

    /**
     * Setting default config options
     * Overriden from AppInstance::getConfigDefaults
     * Uncomment and return array with your default options
     * @return boolean
     */
    protected function getConfigDefaults()
    {
        return false;
    }
}
