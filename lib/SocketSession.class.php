<?php
/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class SocketSession
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description SocketSession class.
/*************************************************************************/
class SocketSession
{
 public $buf = '';
 public $connId;
 public $appInstance;
 public $state = 0;
 public $auth = FALSE;
 public $finished = FALSE;
 public $readLocked = FALSE;
 public $addr;
 /* @method __construct
    @description SocketSession constructor.
    @param integer Connection's ID.
    @param object AppInstance.
    @return void
 */
 public function __construct($connId,$appInstance)
 {
  $this->connId = $connId;
  $this->appInstance = $appInstance;
  $this->init();
 }
 /* @method init
    @description Called when the session constructed.
    @return void
 */
 public function init() {}
 /* @method gets
    @description Reads a first line ended with \n from buffer, removes it from buffer and returns the line.
    @return string Line. Returns false when failed to get a line.
 */
 public function gets()
 {
  $p = strpos($this->buf,"\n");
  if ($p === FALSE) {return FALSE;}
  $r = binarySubstr($this->buf,0,$p+1);
  $this->buf = binarySubstr($this->buf,$p+1);
  return $r;
 }
 /* @method gracefulShutdown
    @description Called when the worker is going to shutdown. 
    @return boolean Ready to shutdown?
 */
 public function gracefulShutdown()
 {
  $this->finish();
  return TRUE;
 }
 /* @method lockRead
    @description Locks read.
    @return void
 */
 public function lockRead()
 {
  $this->readLocked = TRUE;
 }
 /* @method unlockRead
    @description Locks read.
    @return void
 */
 public function unlockRead()
 {
  if (!$this->readLocked) {return;}
  $this->readLocked = FALSE;
  $this->appInstance->onReadEvent(NULL,array($this->connId));
 }
 /* @method onWrite
    @description Called when the connection is ready to accept new data.
    @return void
 */
 public function onWrite() {}
 /* @method write
    @param string Data to send.
    @description Sends data to connection. Note that it just writes to buffer that flushes at every baseloop.
    @return boolean Success.
 */
 public function write($s)
 {
  return $this->appInstance->write($this->connId,$s);
 }
 /* @method writeln
    @param string Data to send.
    @description Sends data and appending \n to connection. Note that it just writes to buffer that flushes at every baseloop.
    @return boolean Success.
 */
 public function writeln($s)
 {
  return $this->appInstance->write($this->connId,$s."\n");
 }
 /* @method finish
    @description Finishes the session. You shouldn't care about pending buffers, it will be flushed properly.
    @return void
 */
 public function finish()
 {
  if ($this->finished) {return;}
  $this->finished = TRUE;
  $this->onFinish();
  $this->appInstance->finishConnection($this->connId);
 }
 /* @method onFinish
    @description Called when the session finished.
    @return void
 */
 public function onFinish()
 {
  unset($this->appInstance->sessions[$this->connId]);
 }
 /* @method stdin
    @description Called when new data recieved.
    @param string New recieved data.
    @return void
 */
 public function stdin($buf)
 {
  $this->buf .= $buf;
 }
}
