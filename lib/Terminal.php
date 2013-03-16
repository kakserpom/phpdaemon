<?php

/**
 * Terminal
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com> 
 */
class Terminal {

	/**
	 * Is color allowed in terminal?
	 * @var boolean
	 */
	protected $enableColor = false;

	/**
	 * Maximum terminal width
	 * @var int
	 */
	protected $columns = 80;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct() {
		$this->columns = $this->getMaxColumns();
	}

	/**
	 * Read a line from STDIN
	 * @return string Line
	 */
	public function readln() {
		return fgets(STDIN);
	}

	/**
	 * Clear the terminal with CLR
	 * @return void
	 */
	public function clearScreen() {
		echo "\x0c";
	}

	/**
	 * Set text style
	 * @param string Style
	 * @return void
	 */
	public function setStyle($c) {
		if ($this->enable_color) {
			echo "\033[".$c.'m';
		}
	}

	/**
	 * Reset style to default
	 * @return void
	 */
	public function resetStyle() {
		if ($this->enable_color) {
			echo "\033[0m";
		}
	}

	/**
	 * Counting terminal char width
	 * @return int
	 */
	protected function getMaxColumns() {
		if (
			preg_match_all("/columns.([0-9]+);/", strtolower(@exec('stty -a | grep columns')), $output)
			&& 2 == sizeof($output)
		) {
			return $output[1][0];
		}

		return 80;
	}

	/**
	 * Draw param (like in man)
	 * @param string Param name
	 * @param string Param description
	 * @param array Param allowed values
	 * @return void
	 */
	public function drawParam($name, $description, $values = '') {
		$paramw = round($this->columns / 3);

		echo "\n";

		$leftcolumn = array();

		$valstr = is_array($values) ? implode('|', array_keys($values)): $values;

		if ('' !== $valstr) {
			$valstr = '=[' . $valstr . ']';
		}

		$paramstr = "  \033[1m--" . $name . $valstr. "\033[0m";

		$pl = strlen($paramstr);
		if ($pl + 2 >= $paramw) {
			$paramw = $pl + 3;
		}

		$descw = $this->columns - $paramw;

		$leftcolumn[] = $paramstr;

		if (is_array($values)) {
			foreach($values as $key => $value) {
				$leftcolumn[] = '    ' . $key . ' - ' . $value; 
			}
		}

		if (strlen($description) <= $descw) {
			$rightcolumn[] = $description;
		} else {
			$m = explode(' ', $description);

			$descstr = '';

			while (sizeof($m) > 0) {
				$el = array_shift($m);

				if (strlen($descstr) + strlen($el) >= $descw) {
					$rightcolumn[] = $descstr;
					$descstr = '';
				} else {
					$descstr .= ' ';
				}

				$descstr .= $el;
			}

			if ('' !== $descstr) {
				$rightcolumn[] = $descstr;
			}
		}

		while (
			sizeof($leftcolumn) > 0
			|| sizeof($rightcolumn) > 0
		) {
			if ($l = array_shift($leftcolumn)) {
				echo str_pad($l, $paramw, ' ');
			} else {
				echo str_repeat(' ', $paramw - 7);
			}
			
			if ($r = array_shift($rightcolumn)) {
				echo $r;
			}

			echo "\n";
		}
	}

	/**
	 * Draw a table
	 * @param array Array of table's rows.
	 * @return void
	 */
	public function drawTable($rows) {
		$pad = [];

		foreach ($rows as $row) {
			foreach ($row as $k => $v) {
				if (substr($k, 0, 1) == '_') {
					continue;
				} 

				if (!isset($pad[$k]) || (strlen($v) > $pad[$k])) {
					$pad[$k] = strlen($v);
				}
			}
		}

		foreach ($rows as $row) {
			if (isset($row['_color'])) {
				$this->setStyle($row['_color']);
			}

			if (isset($row['_bold'])) {
				$this->setStyle('1');
			}

			if (isset($row['_'])) {
				echo $row['_'];
			} else {
				$i = 0;

				foreach ($row as $k => $v) {
					if (substr($k, 0, 1) === '_') {
						continue;
					}

					if ($i > 0) {
						echo "\t";
					}

					echo str_pad($v, $pad[$k]);
					 ++$i;
				}
			}

			$this->resetStyle();
			echo "\n";
		}
	}
}
