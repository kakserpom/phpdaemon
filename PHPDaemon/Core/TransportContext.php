<?php
namespace PHPDaemon\Core;

/**
 * TransportContext
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class TransportContext extends AppInstance
{
    /**
     * Init
     * @return void
     */
    public function init()
    {
        if ($this->isEnabled()) {
        }
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
