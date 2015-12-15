<?php
namespace PHPDaemon\Clients\XMPP;

use PHPDaemon\Clients\XMPP\XMPPRoster;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Timer;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Traits\EventHandlers;
use PHPDaemon\XMLStream\XMLStream;

/**
 * @package    NetworkClients
 * @subpackage XMPPClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * @var boolean
	 */
	public $use_encryption = false;
	
	/**
	 * @var boolean
	 */
	public $authorized;
	
	/**
	 * @var integer
	 */
	public $lastId = 0;
	
	/**
	 * @var XMPPRoster
	 */
	public $roster;
	
	/**
	 * @var XMLStream
	 */
	public $xml;
	
	/**
	 * @var string
	 */
	public $fulljid;
	
	/**
	 * @var integer|string Timer ID
	 */
	public $keepaliveTimer;

	/**
	 * Get next ID
	 * @return string
	 */
	public function getId() {
		$id = ++$this->lastId;
		return dechex($id);
	}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->createXMLStream();
		$this->startXMLStream();
		$this->keepaliveTimer = setTimeout(function ($timer) {
			$this->ping();
		}, 1e6 * 30);
	}

	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->event('disconnect');
		if (isset($this->xml)) {
			$this->xml->finish();
		}
		unset($this->roster);
		if ($this->keepaliveTimer) {
			Timer::remove($this->keepaliveTimer);
		}
	}

	/**
	 * @TODO DESCR
	 * @param string $s
	 */
	public function sendXML($s) {
		//Daemon::log(Debug::dump(['send', $s]));
		$this->write($s);
	}

	/**
	 * @TODO DESCR
	 */
	public function startXMLStream() {
		$this->sendXML('<?xml version="1.0"?>' .
					   '<stream:stream xmlns:stream="http://etherx.jabber.org/streams" version="1.0" xmlns="jabber:client" to="' . $this->host . '" xml:lang="en" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
		);
	}

	/**
	 * @TODO DESCR
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function iqSet($xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="set" id="' . $id . '">' . $xml . '</iq>');
		return true;
	}

	/**
	 * @TODO DESCR
	 * @param  string   $to
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function iqSetTo($to, $xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="set" id="' . $id . '" to="' . htmlspecialchars($to) . '">' . $xml . '</iq>');
		return true;

	}

	/**
	 * @TODO DESCR
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function iqGet($xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="get" id="' . $id . '">' . $xml . '</iq>');
		return true;
	}

	/**
	 * @TODO DESCR
	 * @param  string   $to
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function iqGetTo($to, $xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="get" id="' . $id . '" to="' . htmlspecialchars($to) . '">' . $xml . '</iq>');
		return true;

	}

	/**
	 * @TODO DESCR
	 * @param  string   $to
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function ping($to = null, $cb = null) {
		if (!isset($this->xml)) {
			return false;
		}
		if ($to === null) {
			$to = $this->host;
		}
		//Daemon::log('Sending ping to '.$to);
		return $this->iqGetTo($to, '<ping xmlns="urn:xmpp:ping"/>', $cb);
	}

	/**
	 * @TODO DESCR
	 * @param  string   $ns
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function queryGet($ns, $cb) {
		return $this->iqGet('<query xmlns="' . $ns . '" />', $cb);
	}

	/**
	 * @TODO DESCR
	 * @param  string   $ns
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function querySet($ns, $xml, $cb) {
		return $this->iqSet('<query xmlns="' . $ns . '">' . $xml . '</query>', $cb);
	}

	/**
	 * @TODO DESCR
	 * @param  string   $to
	 * @param  string   $ns
	 * @param  string   $xml
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	public function querySetTo($to, $ns, $xml, $cb) {
		return $this->iqSetTo($to, '<query xmlns="' . $ns . '">' . $xml . '</query>', $cb);
	}

	/**
	 * @TODO DESCR
	 */
	public function createXMLStream() {
		$this->xml = new XMLStream;
		$this->xml->setDefaultNS('jabber:client');
		$this->xml->addXPathHandler('{http://etherx.jabber.org/streams}features', function ($xml) {
			/** @var XMLStream $xml */
			if ($xml->hasSub('starttls') and $this->use_encryption) {
				$this->sendXML("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
			}
			elseif ($xml->hasSub('bind') and $this->authorized) {
				$id = $this->getId();
				$this->iqSet('<bind xmlns="urn:ietf:params:xml:ns:xmpp-bind"><resource>' . $this->path . '</resource></bind>', function ($xml) {
					if ($xml->attrs['type'] === 'result') {
						$this->fulljid = $xml->sub('bind')->sub('jid')->data;
						$jidarray      = explode('/', $this->fulljid);
						$this->jid     = $jidarray[0];
					}
					$this->iqSet('<session xmlns="urn:ietf:params:xml:ns:xmpp-session" />', function ($xml) {
						$this->roster = new XMPPRoster($this);
						if ($this->onConnected) {
							$this->connected = true;
							$this->onConnected->executeAll($this);
							$this->onConnected = null;
						}
						$this->event('connected');
					});
				});
			}
			else {
				if (strlen($this->password)) {
					$this->sendXML("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
				}
				else {
					$this->sendXML("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='ANONYMOUS'/>");
				}
			}
		});
		$this->xml->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}success', function ($xml) {
			$this->authorized = true;
			$this->xml->finish();
			$this->createXMLStream();
			$this->startXMLStream();
		});
		$this->xml->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-sasl}failure', function ($xml) {
			if ($this->onConnected) {
				$this->connected = false;
				call_user_func($this->onConnected, $this);
				$this->onConnected = null;
			}
			$this->finish();
		});
		$this->xml->addXPathHandler('{urn:ietf:params:xml:ns:xmpp-tls}proceed', function ($xml) {
			Daemon::log("XMPPClient: TLS not supported.");
		});
		$this->xml->addXPathHandler('{jabber:client}message', function ($xml) {
			if (isset($xml->attrs['type'])) {
				$payload['type'] = $xml->attrs['type'];
			}
			else {
				$payload['type'] = 'chat';
			}
			$payload['xml']  = $xml;
			$payload['from'] = $xml->attrs['from'];
			if ($xml->hasSub('body')) {
				$payload['body'] = $xml->sub('body')->data;
				$this->event('message', $payload);
			}
		});
	}

	/**
	 * Send XMPP Message
	 * @param string $to
	 * @param string $body
	 * @param string $type
	 * @param string $subject
	 */
	public function message($to, $body, $type = 'chat', $subject = null, $payload = null) {
		if ($type === null) {
			$type = 'chat';
		}

		$to      = htmlspecialchars($to);
		$body    = htmlspecialchars($body);
		$subject = htmlspecialchars($subject);

		$out = '<message from="' . $this->fulljid . '" to="' . $to . '" type="' . $type . '">';
		if ($subject) {
			$out .= '<subject>' . $subject . '</subject>';
		}
		$out .= '<body>' . $body . '</body>';
		if ($payload) {
			$out .= $payload;
		}
		$out .= "</message>";

		$this->sendXML($out);
	}

	/**
	 * Set Presence
	 * @param string  $status
	 * @param string  $show
	 * @param string  $to
	 * @param string  $type
	 * @param integer $priority
	 */
	public function presence($status = null, $show = 'available', $to = null, $type = 'available', $priority = 0) {
		if ($type === 'available') {
			$type = '';
		}
		$to     = htmlspecialchars($to);
		$status = htmlspecialchars($status);
		$show = htmlspecialchars($show);
		$type = htmlspecialchars($type);
		$priority = htmlspecialchars($priority);
		if ($show === 'unavailable') {
			$type = 'unavailable';
		}

		$out = "<presence";
		$out .= ' from="' . $this->fulljid . '"';
		if ($to) {
			$out .= ' to="' . $to . '"';
		}
		if ($type) {
			$out .= ' type="' . $type . '"';
		}
		$inner = '';
		if ($show !== 'available') {
			$inner .= "<show>$show</show>";
		}
		if ($status) {
			$inner .= "<status>$status</status>";
		}
		if ($priority) {
			$inner .= "<priority>$priority</priority>";
		}
		if ($inner === '') {
			$out .= "/>";
		}
		else {
			$out .= '>' . $inner . '</presence>';
		}

		$this->sendXML($out);
	}

	/**
	 * @TODO DESCR
	 * @param string   $jid
	 * @param callable $cb
	 * @callback $cb ( )
	 */
	public function getVCard($jid = null, $cb) {
		$id = $this->getId();
		$this->xml->addIdHandler($id, function ($xml) use ($cb) {
			$vcard    = [];
			$vcardXML = $xml->sub('vcard');
			foreach ($vcardXML->subs as $sub) {
				if ($sub->subs) {
					$vcard[$sub->name] = [];
					foreach ($sub->subs as $sub_child) {
						$vcard[$sub->name][$sub_child->name] = $sub_child->data;
					}
				}
				else {
					$vcard[$sub->name] = $sub->data;
				}
			}
			$vcard['from'] = $xml->attrs['from'];
			call_user_func($cb, $vcard);
		});
		$id = htmlspecialchars($id);
		$jid = htmlspecialchars($jid);
		if ($jid) {
			$this->send('<iq type="get" id="' . $id . '" to="' . $jid . '"><vCard xmlns="vcard-temp" /></iq>');
		}
		else {
			$this->send('<iq type="get" id="' . $id . '"><vCard xmlns="vcard-temp" /></iq>');
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		Timer::setTimeout($this->keepaliveTimer);
		if (isset($this->xml)) {
			$this->xml->feed($this->readUnlimited());
		}
	}
}
