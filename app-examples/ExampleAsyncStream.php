<?php

/**
 * @package Examples
 * @subpackage AsyncStream
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleAsyncStream extends AppInstance {

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleAsyncStreamRequest($this, $upstream, $req);
	}
	
}

class ExampleAsyncStreamRequest extends HTTPRequest {

	public $stream;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		try {
			$this->stream = new AsyncStream('tcpstream://mirror.yandex.ru:80');

			$this->stream->
				onReadData(
					function($stream, $data) {
						$stream->request->combinedOut($data);
					}
				)->
				onEOF(
					function($stream) {
						$stream->request->wakeup();
					}
				)->
				setRequest($this)->
				enable()->
				write("GET / HTTP/1.0\r\nConnection: close\r\nHost: mirror.yandex.ru\r\nAccept: */*\r\n\r\n");
		} catch (BadStreamDescriptorException $e) {
			$this->out('Connection error.');
			$this->finish();
		}
	}

	/**
	 * Called when the request aborted.
	 * @return void
	 */
	 public function onAbort() {
		if ($this->stream) {
			$this->stream->close();
		}
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if (!$this->stream->eof()) {
			$this->sleep(0.1);
		}
	}
	
}
