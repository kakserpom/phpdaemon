<?php

/**
 * @package NetworkClients
 * @subpackage XMPPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class XMPPClient extends NetworkClient {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'port'			=> 5222,
		);
	}

}

class XMPPClientConnection extends NetworkClientConnection {
	public $use_encryption = false;
	public $authorized;
	public $lastId = 0;
	public $roster;
	public $xml;

	/**
	 * Get next ID
	 *
	 * @return integer
	 */
	public function getId() {
		return ++$this->lastId;
	}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->createXMLStream();
		$this->startXMLStream();
	}
	
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		if (isset($this->xml)) {
			$this->xml->finish();
		}
		unset($this->roster);
	}

	public function sendXML($s) {
		$this->write($s);
	}
	public function startXMLStream() {
		$this->sendXML('<?xml version="1.0"?>'.
			'<stream:stream xmlns:stream="http://etherx.jabber.org/streams" version="1.0" xmlns="jabber:client" to="'.$this->host.'" xml:lang="en" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
		);
	}
	public function iqSet($xml, $cb) {

		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="set" id="'.$id.'">'.$xml.'</iq>');

	}

	public function iqGet($xml, $cb) {

		$id = $this->getId();
		$this->xml->addIdHandler($id, $cb);
		$this->sendXML('<iq xmlns="jabber:client" type="get" id="'.$id.'">'.$xml.'</iq>');

	}

	public function queryGet($ns, $cb) {
		$this->iqGet('<query xmlns="'.$ns.'" />', $cb);
	}

	public function createXMLStream() {
		$this->xml = new XMLStream;
		$this->xml->conn = $this;
		$this->xml->addXPathHandler('{http://etherx.jabber.org/streams}features', function ($xml) {
			if ($xml->hasSub('starttls') and $this->use_encryption) {
				$this->sendXML("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
			} elseif ($xml->hasSub('bind') and $this->authorized) {
				$id = $this->getId();
				$this->iqSet('<bind xmlns="urn:ietf:params:xml:ns:xmpp-bind"><resource>'.$this->path.'</resource></bind>', function ($xml) {
					if($xml->attrs['type'] == 'result') {
						$this->fulljid = $xml->sub('bind')->sub('jid')->data;
						$jidarray = explode('/',$this->fulljid);
						$this->jid = $jidarray[0];
					}
					$this->iqSet('<session xmlns="urn:ietf:params:xml:ns:xmpp-session" />', function() {
						$this->roster = new XMPPRoster($this);
						if ($this->onConnected) {
							$this->connected = true;
							call_user_func($this->onConnected, $this);
							$this->onConnected = null;
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

	public function addEventHandler($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		$this->eventHandlers[$event][] = $cb;
	}

	public function event() {
		$args = func_get_args();
		$name = array_shift($args);
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
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
	public function presence($status = null, $show = 'available', $to = null, $type='available', $priority=0) {
		if($type == 'available') $type = '';
		$to	 = htmlspecialchars($to);
		$status = htmlspecialchars($status);
		if($show == 'unavailable') $type = 'unavailable';
		
		$out = "<presence";
		if($to) $out .= " to=\"$to\"";
		if($type) $out .= " type='$type'";
		if($show == 'available' and !$status) {
			$out .= "/>";
		} else {
			$out .= ">";
			if($show != 'available') $out .= "<show>$show</show>";
			if($status) $out .= "<status>$status</status>";
			if($priority) $out .= "<priority>$priority</priority>";
			$out .= "</presence>";
		}
		
		$this->sendXML($out);
	}

	public function getVCard($jid = null, $cb) {
		$id = $this->getId();
		$this->addIdHandler($id, function ($xml) use ($cb) {
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
		//Daemon::log(Debug::dump(['buf', $buf]));
		$this->xml->feed($buf);
	}	
}
