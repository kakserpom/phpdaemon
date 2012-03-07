<?php

define("EXTERNAL_APP_CLASSES_DIR", dirname(__FILE__) . "/../../../app/") ;

require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/Base_Class.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/DB_Connection.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/DB_GenericTable.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/Tools.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/Crypto.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/Session.class.php") ;
require_once(EXTERNAL_APP_CLASSES_DIR . "/modules/app/classes/wsServer.class.php") ;

class ExampleGenericWebSocket extends AppInstance {
 
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->WS = Daemon::$appResolver->getInstanceByAppName('genericWebSocketServer')) {
			$this->WS->addRoute('exampleApp', function ($client) {
					return new ExampleGenericWebSocketRoute($client);
				}
			);

			// If you want to manage timeout events :
			$appInstance = $this ;
			$this->WS->registerEventTimeout(function() use ($appInstance) {
					return $appInstance->onEventTimeout() ;
				}
			) ;
		}
	}

    /**
     * Called when a timeout event is raised
     * @return void
     */
	public function onEventTimeout() {
	 
	}
    
    /**
     * Called when application instance is going to shutdown
     * @todo protected?
     * @return boolean Ready to shutdown?
     */
    public function onShutdown() {
        return TRUE;
    }

}

class ExampleGenericWebSocketRoute extends WebSocketRoute {
 
	/**
	 * Called when new frame received.
	 * @param string Frame's contents.
	 * @param string Frame's type. ("STRING" or "BINARY")
	 * @return void
	 */
	public function onFrame($data, $type) {
		if ($data === 'ping') {
			$this->client->sendFrame('pong', "STRING", function($client) {
					Daemon::log($client->clientAddr . ' : SEND pong');
				}
			);
			return ;
  		}
	}

	/**
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {
	 
        Daemon::log($this->client->clientAddr . ' : Handshake success') ;
	}
	
	/**
	 * Called when session finished.
	 * @return void
	 */
	public function onFinish() {
	 
		Daemon::log($this->client->clientAddr . ' : Disconnected');
	}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
	 
		Daemon::log($this->client->clientAddr . ' : Gracefully disconnecting (as requested by server)') ;
		return TRUE ;
	}
}
