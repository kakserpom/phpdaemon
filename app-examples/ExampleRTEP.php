<?php
return new ExampleRTEP;
class ExampleRTEP extends AppInstance
{
 public $RTEPClient;
 public $RTEP;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  $o = $this;
  $this->RTEPClient = Daemon::$appResolver->getInstanceByAppName('RTEPClient');
  $this->RTEP = Daemon::$appResolver->getInstanceByAppName('RTEP');
  if ($this->RTEPClient && $this->RTEPClient->client && $this->RTEP)
  {
   $this->RTEP->eventGroups['testEvent'] = array(function($session,$packet,$args = array())
   {
    $session->addEvent('testEvent');
   }); 
   $this->RTEPClient->client->addEventCallback('testEvent',function($event) use ($o)
   {
    Daemon::log('Caught event '.$event['name'].'.');
   });
  }
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
 public function beginRequest($req,$upstream) {return new ExampleRTEPRequest($this,$upstream,$req);}
}
class ExampleRTEPRequest extends Request
{
 /* @method run
    @description Called when request iterated.
    @return integer Status.
 */
 public function run()
 {
  $this->appInstance->RTEPClient->client->request(array(
   'op' => 'event',
   'event' => array(
     'name' => 'testEvent',
     'somevar' => 'somevalue... ',
  )));
  echo 'OK';
  return Request::DONE;
 }
}
