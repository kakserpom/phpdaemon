<?php
class XMPPRoster {
	use EventHandlers;

	public $xmpp;
	public $roster_array = array();
	public $track_presence = true;
	public $auto_subscribe = true;
	public $ns = 'jabber:iq:roster';
	
	public function __construct($xmpp) {
		$this->xmpp = $xmpp;

		$this->xmpp->xml->addXPathHandler('{jabber:client}presence', function ($xml) {
			$payload = array();
			$payload['type'] = (isset($xml->attrs['type'])) ? $xml->attrs['type'] : 'available';
			$payload['show'] = (isset($xml->sub('show')->data)) ? $xml->sub('show')->data : $payload['type'];
			$payload['from'] = $xml->attrs['from'];
			$payload['status'] = (isset($xml->sub('status')->data)) ? $xml->sub('status')->data : '';
			$payload['priority'] = (isset($xml->sub('priority')->data)) ? intval($xml->sub('priority')->data) : 0;
			$payload['xml'] = $xml;
			if (($payload['from'] === $this->xmpp->fulljid) && $payload['type'] === 'unavailable') {
				$this->xmpp->finish();
			}
			if ($this->track_presence) {
				$this->setPresence($payload['from'], $payload['priority'], $payload['show'], $payload['status']);
			}
			//Daemon::log("Presence: {$payload['from']} [{$payload['show']}] {$payload['status']}");
			if(array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribe') {
				if($this->auto_subscribe) {
					$this->xmpp->sendXML("<presence type='subscribed' to='{$xml->attrs['from']}' from='{$this->xmpp->fulljid}' />");
					$this->xmpp->sendXML("<presence type='subscribe' to='{$xml->attrs['from']}' from='{$this->xmpp->fulljid}' />");
				}
				$this->event('subscription_requested', $payload);
			} elseif(array_key_exists('type', $xml->attrs) and $xml->attrs['type'] == 'subscribed') {
				$this->event('subscription_accepted', $payload);
			} else {
				$this->event('presence', $payload);
			}
		});
		$this->fetch();

	}

	public function rosterSet($xml, $cb = null) {
		$this->xmpp->querySetTo($this->xmpp->fulljid, $this->ns, $xml, $cb);
	}

	public function setSubscription($jid, $type, $cb = null) {
		$this->rosterSet('<item jid="'.htmlspecialchars($jid).'" subscription="'.htmlspecialchars($type).'" />', $cb);
	} 
	public function fetch($cb = null) {
		$this->xmpp->queryGet($this->ns, function ($xml) use ($cb) {
			$status = "result";
			$xmlroster = $xml->sub('query');
			$contacts = array();
			foreach($xmlroster->subs as $item) {
				$groups = array();
				if ($item->name == 'item') {
					$jid = $item->attrs['jid']; //REQUIRED
					$name = isset($item->attrs['name']) ? $item->attrs['name'] : ''; //MAY
					$subscription = $item->attrs['subscription'];
					foreach($item->subs as $subitem) {
						if ($subitem->name == 'group') {
							$groups[] = $subitem->data;
						}
					}
					$contacts[] = array($jid, $subscription, $name, $groups); //Store for action if no errors happen
				} else {
					$status = "error";
				}
			}
			if ($status == "result") { //No errors, add contacts
				foreach($contacts as $contact) {
					$this->_addContact($contact[0], $contact[1], $contact[2], $contact[3]);
				}
			}
			if ($xml->attrs['type'] == 'set') {
				$this->xmpp->sendXML('<iq type="reply" id="'.$xml->attrs['id'].'" to="'.$xml->attrs['from'].'" />');
			}
			if ($cb) {
				call_user_func($cb, $status);
			}
		});
	}

	/**
	 *
	 * Add given contact to roster
	 *
	 * @param string $jid
	 * @param string $subscription
	 * @param string $name
	 * @param array $groups
	 */
	public function _addContact($jid, $subscription, $name='', $groups=array()) {
		$contact = array('jid' => $jid, 'subscription' => $subscription, 'name' => $name, 'groups' => $groups);
		if ($this->isContact($jid)) {
			$this->roster_array[$jid]['contact'] = $contact;
		} else {
			$this->roster_array[$jid] = array('contact' => $contact);
		}
	}

	/**
	 * 
	 * Retrieve contact via jid
	 *
	 * @param string $jid
	 */
	public function getContact($jid) {
		if ($this->isContact($jid)) {
			return $this->roster_array[$jid]['contact'];
		}
		return null;
	}

	/**
	 *
	 * Discover if a contact exists in the roster via jid
	 *
	 * @param string $jid
	 */
	public function isContact($jid) {
		return array_key_exists($jid, $this->roster_array);
	}

	/**
	 *
	 * Set presence
	 *
	 * @param string $presence
	 * @param integer $priority
	 * @param string $show
	 * @param string $status
	*/
	public function setPresence($presence, $priority, $show, $status) {
		list($jid, $resource) = explode('/', $presence . '/');
		if ($show != 'unavailable') {
			if (!$this->isContact($jid)) {
				$this->_addContact($jid, 'not-in-roster');
			}
			$this->roster_array[$jid]['presence'][$resource] = ['priority' => $priority, 'show' => $show, 'status' => $status];
		} else { //Nuke unavailable resources to save memory
			unset($this->roster_array[$jid]['resource'][$resource]);
		}
	}

	/*
	 *
	 * Return best presence for jid
	 *
	 * @param string $jid
	 * @param array
	 */
	public function getPresence($jid) {
		$split = split("/", $jid);
		$jid = $split[0];
		if (!$this->isContact($jid)) {
			return false;
		}
		$current = ['resource' => '', 'active' => '', 'priority' => -129, 'show' => '', 'status' => '']; //Priorities can only be -128 = 127
		foreach ($this->roster_array[$jid]['presence'] as $resource => $presence) {
			//Highest available priority or just highest priority
			if ($presence['priority'] > $current['priority'] && (($presence['show'] == "chat" || $presence['show'] == "available") or ($current['show'] != "chat" or $current['show'] != "available"))) {
				$current = $presence;
				$current['resource'] = $resource;
			}
		}
		return $current;
	}
}
