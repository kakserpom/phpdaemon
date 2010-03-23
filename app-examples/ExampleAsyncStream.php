<?php
return new ExampleAsyncStream;
class ExampleAsyncStream extends AppInstance
{
 public function beginRequest($req,$upstream) {return new ExampleAsyncStreamRequest($this,$upstream,$req);}
}
class ExampleAsyncStreamRequest extends Request
{
 public $stream;
 public function init()
 {
  try
  {
   $this->stream = new AsyncStream('tcpstream://mirror.yandex.ru:80');
   $this->stream
   ->onReadData(function($stream,$data) {$stream->request->combinedOut($data);})
   ->onEOF(function($stream) {$stream->request->wakeup();})
   ->setRequest($this)
   ->enable()
   ->write("GET / HTTP/1.0\r\nConnection: close\r\nHost: mirror.yandex.ru\r\nAccept: */*\r\n\r\n");
  }
  catch (BadStreamDescriptorException $e)
  {
   $this->out('Connection error.');
   $this->finish();
  }
 }
 public function onAbort()
 {
  if ($this->stream) {$this->stream->close();}
 }
 public function run()
 {
  if (!$this->stream->eof()) {$this->sleep();}
  return 1;
 }
}
