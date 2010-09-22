<?php

return new ExampleAsyncStream;

class ExampleAsyncStream extends AppInstance {

	/**
	 * @method beginRequest
	 * @description Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleAsyncStreamRequest($this, $upstream, $req);
	}
}

class ExampleAsyncStreamRequest extends Request {

	public $stream;

	/**
	 * @method init
	 * @description Constructor.
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
	 * @method onAbort
	 * @description Called when the request aborted.
	 * @return void
	 */
	 public function onAbort() {
		if ($this->stream) {
			$this->stream->close();
		}
	}

	/**
	 * @method run
	 * @description Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if (!$this->stream->eof()) {
			$this->sleep();
		}

		return Request::DONE;
	}
}
