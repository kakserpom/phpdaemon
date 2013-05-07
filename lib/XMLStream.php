<?php
class XMLStream {
	use EventHandlers;

	protected $parser;
	protected $xml_depth = 0;
	protected $current_ns = array();
	protected $idhandlers = array();
	protected $xpathhandlers = array();
	protected $default_ns;

	public function __construct() {
		$this->parser = xml_parser_create('UTF-8');
		xml_parser_set_option($this->parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}

	public function setDefaultNS($ns) {
		$this->default_ns = $ns;
	}

	public function finish() {
		$this->xml_depth = 0;
		$this->current_ns = [];
		$this->idhandlers = [];
		$this->xpathhandlers = [];
		$this->eventHandlers = [];
	}

	public function __destroy() {
		if ($this->parser) {
			xml_parse($this->parser, '', true);
			xml_parser_free($this->parser);
		}
	}

	public function feed($buf) {
		xml_parse($this->parser, $buf, false);
	}

	public function finalize() {
		xml_parse($this->parser, '', true);
	}

	public function startXML($parser, $name, $attr) {
		++$this->xml_depth;
		if (array_key_exists('XMLNS', $attr)) {
			$this->current_ns[$this->xml_depth] = $attr['XMLNS'];
		} else {
			$this->current_ns[$this->xml_depth] = $this->current_ns[$this->xml_depth - 1];
			if(!$this->current_ns[$this->xml_depth]) $this->current_ns[$this->xml_depth] = $this->default_ns;
		}
		$ns = $this->current_ns[$this->xml_depth];
		foreach($attr as $key => $value) {
			if(strstr($key, ':')) {
				$key = explode(':', $key);
				$key = $key[1];
				$this->ns_map[$key] = $value;
			}
		}
		if(!strstr($name, ":") === false)
		{
			$name = explode(':', $name);
			$ns = $this->ns_map[$name[0]];
			$name = $name[1];
		}
		$obj = new XMLStream_Object($name, $ns, $attr);
		if($this->xml_depth > 1) {
			$this->xmlobj[$this->xml_depth - 1]->subs[] = $obj;
		}
		$this->xmlobj[$this->xml_depth] = $obj;
	}

	/**
	 * XML end callback
	 * 
	 * @see xml_set_element_handler
	 *
	 * @param resource $parser
	 * @param string   $name
	 */
	public function endXML($parser, $name) {
		--$this->xml_depth;
		if ($this->xml_depth == 1) {
			foreach($this->xpathhandlers as $handler) {
				if (is_array($this->xmlobj) && array_key_exists(2, $this->xmlobj)) {
					$searchxml = $this->xmlobj[2];
					$nstag = array_shift($handler[0]);
					if (($nstag[0] == null or $searchxml->ns == $nstag[0]) and ($nstag[1] == "*" or $nstag[1] == $searchxml->name)) {
						foreach($handler[0] as $nstag) {
							if ($searchxml !== null and $searchxml->hasSub($nstag[1], $ns=$nstag[0])) {
								$searchxml = $searchxml->sub($nstag[1], $ns=$nstag[0]);
							} else {
								$searchxml = null;
								break;
							}
						}
						if ($searchxml !== null) {
							if ($handler[2] === null) {
								$handler[2] = $this;
							}
							call_user_func($handler[1], $this->xmlobj[2]);
						}
					}
				}
			}
			foreach($this->idhandlers as $id => $handler) {
				if(array_key_exists('id', $this->xmlobj[2]->attrs) and $this->xmlobj[2]->attrs['id'] == $id) {
					call_user_func($handler, $this->xmlobj[2]);
					#id handlers are only used once
					unset($this->idhandlers[$id]);
					break;
				}
			}
			if(is_array($this->xmlobj)) {
				$this->xmlobj = array_slice($this->xmlobj, 0, 1);
				if(isset($this->xmlobj[0]) && $this->xmlobj[0] instanceof XMLStream_Object) {
					$this->xmlobj[0]->subs = null;
				}
			}
			unset($this->xmlobj[2]);
		}
		if($this->xml_depth == 0) {
			$this->event('streamEnd');
		}
	}

	/**
	 * XML character callback
	 * @see xml_set_character_data_handler
	 *
	 * @param resource $parser
	 * @param string   $data
	 */
	public function charXML($parser, $data) {
		if(array_key_exists($this->xml_depth, $this->xmlobj)) {
			$this->xmlobj[$this->xml_depth]->data .= $data;
		}
	}


/**
	 * Get next ID
	 *
	 * @return integer
	 */
	public function getId() {
		$this->lastid++;
		return $this->lastid;
	}

	/**
	 * Set SSL
	 *
	 * @return integer
	 */
	public function useSSL($use=true) {
		$this->use_ssl = $use;
	}

	/**
	 * Add ID Handler
	 *
	 * @param integer $id
	 * @param string  $pointer
	 * @param string  $obj
	 */
	public function addIdHandler($id, $cb) {
		if ($cb === null) {
			return;
		}
		$this->idhandlers[$id] = $cb;
	}

	/**
	 * Add XPath Handler
	 *
	 * @param string $xpath
	 * @param string $pointer
	 * @param
	 */
	public function addXPathHandler($xpath, $cb, $obj = null) {
		if (preg_match_all("/\(?{[^\}]+}\)?(\/?)[^\/]+/", $xpath, $regs)) {
			$ns_tags = $regs[0];
		} else {
			$ns_tags = array($xpath);
		}
		foreach($ns_tags as $ns_tag) {
			list($l, $r) = explode("}", $ns_tag);
			if ($r != null) {
				$xpart = array(substr($l, 1), $r);
			} else {
				$xpart = array(null, $l);
			}
			$xpath_array[] = $xpart;
		}
		$this->xpathhandlers[] = array($xpath_array, $cb, $obj, $xpath);
	}

}


class XMLStream_Object {
	/**
	 * Tag name
	 *
	 * @var string
	 */
	public $name;
	
	/**
	 * Namespace
	 *
	 * @var string
	 */
	public $ns;
	
	/**
	 * Attributes
	 *
	 * @var array
	 */
	public $attrs = array();
	
	/**
	 * Subs?
	 *
	 * @var array
	 */
	public $subs = array();
	
	/**
	 * Node data
	 * 
	 * @var string
	 */
	public $data = '';

	/**
	 * Constructor
	 *
	 * @param string $name
	 * @param string $ns
	 * @param array  $attrs
	 * @param string $data
	 */
	public function __construct($name, $ns = '', $attrs = array(), $data = '') {
		$this->name = strtolower($name);
		$this->ns   = $ns;
		if(is_array($attrs) && count($attrs)) {
			foreach($attrs as $key => $value) {
				$this->attrs[strtolower($key)] = $value;
			}
		}
		$this->data = $data;
	}

	/**
	 * Dump this XML Object to output.
	 *
	 * @param integer $depth
	 */
	public function printObj($depth = 0) {
		$s = str_repeat("\t", $depth) . $this->name . " " . $this->ns . ' ' . $this->data . "\n";
		foreach($this->subs as $sub) {
			$s .= $sub->printObj($depth + 1);
		}
		return $s;
	}

	/**
	 * Return this XML Object in xml notation
	 *
	 * @param string $str
	 */
	public function toString($str = '') {
		$str .= "<{$this->name} xmlns='{$this->ns}' ";
		foreach($this->attrs as $key => $value) {
			if($key != 'xmlns') {
				$value = htmlspecialchars($value);
				$str .= "$key='$value' ";
			}
		}
		$str .= ">";
		foreach($this->subs as $sub) {
			$str .= $sub->toString();
		}
		$body = htmlspecialchars($this->data);
		$str .= "$body</{$this->name}>";
		return $str;
	}

	/**
	 * Has this XML Object the given sub?
	 * 
	 * @param string $name
	 * @return boolean
	 */
	public function hasSub($name, $ns = null) {
		foreach($this->subs as $sub) {
			if(($name == "*" or $sub->name == $name) and ($ns == null or $sub->ns == $ns)) return true;
		}
		return false;
	}

	/**
	 * Return a sub
	 *
	 * @param string $name
	 * @param string $attrs
	 * @param string $ns
	 */
	public function sub($name, $attrs = null, $ns = null) {
		//@TODO: attrs is ignored
		foreach ($this->subs as $sub) {
			if ($sub->name == $name and ($ns == null or $sub->ns == $ns)) {
				return $sub;
			}
		}
		return null;
	}
}
