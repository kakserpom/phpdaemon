<?php
namespace PHPDaemon\Request;

interface IRequestUpstream {

	/**
	 * @return boolean
	 */
	public function requestOut($req, $s);

	/**
	 * Handles the output from downstream requests.
	 * @return boolean Succcess.
	 */
	public function endRequest($req, $appStatus, $protoStatus);

	/**
	 * Frees this request
	 * @return void
	 */
	public function freeRequest($req);

	/**
	 * Send Bad request
	 * @return void
	 */
	public function badRequest($req);
}