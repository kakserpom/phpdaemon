<?php
namespace PHPDaemon\XMLStream;

use PHPDaemon\Traits\EventHandlers;
use PHPDaemon\XMLStream\XMLStreamObject;

class XMLStream {
	use \PHPDaemon\Traits\EventHandlers;
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $parser;
	protected $xml_depth = 0;
	protected $current_ns = [];
	protected $idhandlers = [];
	protected $xpathhandlers = [];
	protected $default_ns;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct() {
		$this->parser = xml_parser_create('UTF-8');
		xml_parser_set_option($this->parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}

	/**
	 * Set default namespace
	 * @param string $ns
	 * @return void
	 */
	public function setDefaultNS($ns) {
		$this->default_ns = $ns;
	}

	/**
	 * Finishes the stream
	 * @return void
	 */
	public function finish() {
		$this->xml_depth     = 0;
		$this->current_ns    = [];
		$this->idhandlers    = [];
		$this->xpathhandlers = [];
		$this->eventHandlers = [];
	}

	/**
	 * Destructor
	 * @return void
	 */
	public function __destroy() {
		if ($this->parser) {
			xml_parse($this->parser, '', true);
			xml_parser_free($this->parser);
		}
	}

	/**
	 * Feed stream
	 * @param string $buf
	 * @return void
	 */
	public function feed($buf) {
		xml_parse($this->parser, $buf, false);
	}

	/**
	 * Finalize stream
	 * @return void
	 */
	public function finalize() {
		xml_parse($this->parser, '', true);
	}

	/**
	 * XML start callback
	 *
	 * @see xml_set_element_handler
	 * @param resource $parser
	 * @param string $name
	 * @param array $attr
	 * @return void
	 */
	public function startXML($parser, $name, $attr) {
		++$this->xml_depth;
		if (array_key_exists('XMLNS', $attr)) {
			$this->current_ns[$this->xml_depth] = $attr['XMLNS'];
		}
		else {
			$this->current_ns[$this->xml_depth] = $this->current_ns[$this->xml_depth - 1];
			if (!$this->current_ns[$this->xml_depth]) {
				$this->current_ns[$this->xml_depth] = $this->default_ns;
			}
		}
		$ns = $this->current_ns[$this->xml_depth];
		foreach ($attr as $key => $value) {
			if (strpos($key, ':') !== false) {
				$key                = explode(':', $key);
				$key                = $key[1];
				$this->ns_map[$key] = $value;
			}
		}
		if (strpos($name, ':') !== false) {
			$name = explode(':', $name);
			$ns   = $this->ns_map[$name[0]];
			$name = $name[1];
		}
		$obj = new XMLStreamObject($name, $ns, $attr);
		if ($this->xml_depth > 1) {
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
	 * @param string $name
	 * @return void
	 */
	public function endXML($parser, $name) {
		--$this->xml_depth;
		if ($this->xml_depth === 1) {
			foreach ($this->xpathhandlers as $handler) {
				if (is_array($this->xmlobj) && array_key_exists(2, $this->xmlobj)) {
					$searchxml = $this->xmlobj[2];
					$nstag     = array_shift($handler[0]);
					if (($nstag[0] === null or $searchxml->ns === $nstag[0]) and ($nstag[1] === "*" or $nstag[1] === $searchxml->name)) {
						foreach ($handler[0] as $nstag) {
							if ($searchxml !== null and $searchxml->hasSub($nstag[1], $ns = $nstag[0])) {
								$searchxml = $searchxml->sub($nstag[1], $ns = $nstag[0]);
							}
							else {
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
			foreach ($this->idhandlers as $id => $handler) {
				if (array_key_exists('id', $this->xmlobj[2]->attrs) and $this->xmlobj[2]->attrs['id'] == $id) {
					call_user_func($handler, $this->xmlobj[2]);
					#id handlers are only used once
					unset($this->idhandlers[$id]);
					break;
				}
			}
			if (is_array($this->xmlobj)) {
				$this->xmlobj = array_slice($this->xmlobj, 0, 1);
				if (isset($this->xmlobj[0]) && $this->xmlobj[0] instanceof XMLStreamObject) {
					$this->xmlobj[0]->subs = null;
				}
			}
			unset($this->xmlobj[2]);
		}
		if ($this->xml_depth === 0) {
			$this->event('streamEnd');
		}
	}

	/**
	 * XML character callback
	 * @see xml_set_character_data_handler
	 *
	 * @param resource $parser
	 * @param string $data
	 */
	public function charXML($parser, $data) {
		if (array_key_exists($this->xml_depth, $this->xmlobj)) {
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
	 * @param bool $use
	 * @return integer
	 */
	public function useSSL($use = true) {
		$this->use_ssl = $use;
	}

	/**
	 * Add ID Handler
	 *
	 * @param integer $id
	 * @param callable $cb
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
	 * @param \Closure $cb
	 * @param null $obj
	 */
	public function addXPathHandler($xpath, $cb, $obj = null) {
		if (preg_match_all("/\(?{[^\}]+}\)?(\/?)[^\/]+/", $xpath, $regs)) {
			$ns_tags = $regs[0];
		}
		else {
			$ns_tags = [$xpath];
		}
		foreach ($ns_tags as $ns_tag) {
			list($l, $r) = explode("}", $ns_tag);
			if ($r !== null) {
				$xpart = [substr($l, 1), $r];
			}
			else {
				$xpart = [null, $l];
			}
			$xpath_array[] = $xpart;
		}
		$this->xpathhandlers[] = [$xpath_array, $cb, $obj, $xpath];
	}

}
