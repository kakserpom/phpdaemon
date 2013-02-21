<?php

/**
 * @package NetworkClients
 * @subpackage XMPPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class XMPPClientConnection extends NetworkClientConnection {
	use EventHandlers;

	public $use_encryption = false;
	public $authorized;
	public $lastId = 0;
	public $roster;
	public $xml;
	public $fulljid;
	public $keepaliveTimer;

	/**
	 * Get next ID
	 *
	 * @return integer
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
		$this->keepaliveTimer = setTimeout(function($timer) {
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

	public function sendXML($s) {
		//Daemon::log(Debug::dump(['send', $s]));
		$this->write($s);
	}
	public function startXMLStream() {
		$this->sendXML('<?xml version="1.0"?>'.
			'<stream:stream xmlns:stream="http://etherx.jabber.org/streams" version="1.0" xmlns="jabber:client" to="'.$this->host.'" xml:lang="en" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
		);
	}
	public function iqSet($xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="set" id="'.$id.'">'.$xml.'</iq>');
		return true;
	}

	public function iqSetTo($to, $xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="set" id="'.$id.'" to="'.htmlspecialchars($to).'">'.$xml.'</iq>');
		return true;

	}

	public function iqGet($xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="get" id="'.$id.'">'.$xml.'</iq>');
		return true;
	}

	public function iqGetTo($to, $xml, $cb) {
		if (!isset($this->xml)) {
			return false;
		}
		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="get" id="'.$id.'" to="'.htmlspecialchars($to).'">'.$xml.'</iq>');
		return true;

	}

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

	public function queryGet($ns, $cb) {
		return $this->iqGet('<query xmlns="'.$ns.'" />', $cb);
	}


	public function querySet($ns, $xml, $cb) {
		return $this->iqSet('<query xmlns="'.$ns.'">'.$xml.'</query>', $cb);
	}

	public function querySetTo($to, $ns, $xml, $cb) {
		return $this->iqSetTo($to, '<query xmlns="'.$ns.'">'.$xml.'</query>', $cb);
	}

	public function createXMLStream() {
		$this->xml = new XMLStream;
		$this->xml->setDefaultNS('jabber:client');
		$this->xml->conn = $this;
		$conn = $this;
		$this->xml->addXPathHandler('{http://etherx.jabber.org/streams}features', function ($xml) use ($conn) {
			if ($xml->hasSub('starttls') and $this->use_encryption) {
				$conn->sendXML("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
			} elseif ($xml->hasSub('bind') and $this->authorized) {
				$id = $this->getId();
				$this->iqSet('<bind xmlns="urn:ietf:params:xml:ns:xmpp-bind"><resource>'.$this->path.'</resource></bind>', function ($xml) use ($conn) {
					if($xml->attrs['type'] == 'result') {
						$conn->fulljid = $xml->sub('bind')->sub('jid')->data;
						$jidarray = explode('/',$this->fulljid);
						$conn->jid = $jidarray[0];
					}
					$conn->iqSet('<session xmlns="urn:ietf:params:xml:ns:xmpp-session" />', function($xml) use ($conn) {
						$conn->roster = new XMPPRoster($conn);
						if ($conn->onConnected) {
							$conn->connected = true;
							$conn->onConnected->executeAll($conn, $this);
							$conn->onConnected = null;
						}
						$this->event('connected');
					});
				});
			} else {
				if (strlen($this->password)) {
					$this->sendXML("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
				} else {
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
		$this->xml->addXPathHandler('{jabber:client}message', function($xml) {
			if( isset($xml->attrs['type'])) {
				$payload['type'] = $xml->attrs['type'];
			} else {
				$payload['type'] = 'chat';
			}
			$payload['xml'] = $xml;
			$payload['from'] = $xml->attrs['from'];
			if ($xml->hasSub('body')) {
				$payload['body'] = $xml->sub('body')->data;
				$this->event('message', $payload);
			}
		});
	}

	/**
	 * Send XMPP Message
	 *
	 * @param string $to
	 * @param string $body
	 * @param string $type
	 * @param string $subject
	 */
	public function message($to, $body, $type = 'chat', $subject = null, $payload = null) {
	    if (is_null($type)) {
			$type = 'chat';
	    }
	    
		$to	  = htmlspecialchars($to);
		$body	= htmlspecialchars($body);
		$subject = htmlspecialchars($subject);
		
		$out = '<message from="'.$this->fulljid.'" to="'.$to.'" type="'.$type.'">';
		if($subject) {
			$out .= '<subject>'.$subject.'</subject>';
		}
		$out .= '<body>'.$body.'</body>';
		if ($payload) {
			$out .= $payload;
		}
		$out .= "</message>";
		
		$this->sendXML($out);
	}

	/**
	 * Set Presence
	 *
	 * @param string $status
	 * @param string $show
	 * @param string $to
	 */
	public function presence($status = null, $show = 'available', $to = null, $type='available', $priority = 0 ) {
		if($type == 'available') $type = '';
		$to	 = htmlspecialchars($to);
		$status = htmlspecialchars($status);
		if($show == 'unavailable') $type = 'unavailable';
		
		$out = "<presence";
		$out .= ' from="'.$this->fulljid.'"';
		if ($to) {
			$out .= ' to="'.$to.'"';
		}
		if ($type) {
			$out .= ' type="'.$type.'"';
		}
		$inner = '';
		if ($show != 'available') {
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
		} else {
			$out .= '>' . $inner . '</presence>';
		}
		
		$this->sendXML($out);
	}

	public function getVCard($jid = null, $cb) {
		$id = $this->getId();
		$this->xml->addIdHandler($id, function ($xml) use ($cb) {
			$vcard = array();
			$vcardXML = $xml->sub('vcard');
			foreach ($vcardXML->subs as $sub) {
				if ($sub->subs) {
					$vcard[$sub->name] = array();
					foreach ($sub->subs as $sub_child) {
						$vcard[$sub->name][$sub_child->name] = $sub_child->data;
					}
				} else {
					$vcard[$sub->name] = $sub->data;
				}
			}
			$vcard['from'] = $xml->attrs['from'];
			call_user_func($cb, $vcard);
		});
		if($jid) {
			$this->send('<iq type="get" id="'.$id.'" to="'.$jid.'"><vCard xmlns="vcard-temp" /></iq>');
		} else {
			$this->send('<iq type="get" id="'.$id.'"><vCard xmlns="vcard-temp" /></iq>');
		}
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		Timer::setTimeout($this->keepaliveTimer);
		if (isset($this->xml)) {
			$this->xml->feed($buf);
		}
	}
}
