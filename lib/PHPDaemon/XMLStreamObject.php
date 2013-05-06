<?php
namespace PHPDaemon;

class XMLStreamObject {
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
	 * @param array $attrs
	 * @param string $data
	 */
	public function __construct($name, $ns = '', $attrs = array(), $data = '') {
		$this->name = strtolower($name);
		$this->ns   = $ns;
		if (is_array($attrs) && count($attrs)) {
			foreach ($attrs as $key => $value) {
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
		foreach ($this->subs as $sub) {
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
		foreach ($this->attrs as $key => $value) {
			if ($key != 'xmlns') {
				$value = htmlspecialchars($value);
				$str .= "$key='$value' ";
			}
		}
		$str .= ">";
		foreach ($this->subs as $sub) {
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
		foreach ($this->subs as $sub) {
			if (($name == "*" or $sub->name == $name) and ($ns == null or $sub->ns == $ns)) {
				return true;
			}
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