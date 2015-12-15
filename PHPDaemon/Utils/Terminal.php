<?php
namespace PHPDaemon\Utils;

/**
 * Terminal
 * @package PHPDaemon\Utils
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Terminal {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var boolean Is color allowed in terminal?
	 */
	protected $enableColor = false;

	/**
	 * @var integer Maximum terminal width
	 */
	protected $columns = 80;

	/**
	 * Constructor
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
	 * Enables/disable color
	 * @param  boolean $bool Enable?
	 * @return void
	 */
	public function enableColor($bool = true) {
		$this->enableColor = $bool;
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
	 * @param  string $c Style
	 * @return void
	 */
	public function setStyle($c) {
		if ($this->enableColor) {
			echo "\033[" . $c . 'm';
		}
	}

	/**
	 * Reset style to default
	 * @return void
	 */
	public function resetStyle() {
		if ($this->enableColor) {
			echo "\033[0m";
		}
	}

	/**
	 * Counting terminal char width
	 * @return integer
	 */
	protected function getMaxColumns() {
		if (
				preg_match_all("/columns.([0-9]+);/", strtolower(@exec('stty -a | grep columns')), $output)
				&& sizeof($output) === 2
		) {
			return $output[1][0];
		}

		return 80;
	}

	/**
	 * Draw param (like in man)
	 * @param string $name        Param name
	 * @param string $description Param description
	 * @param array  $values      Param allowed values
	 * @return void
	 */
	public function drawParam($name, $description, $values = '') {
		$paramw = round($this->columns / 3);

		echo "\n";

		$leftcolumn = [];

		$valstr = is_array($values) ? implode('|', array_keys($values)) : $values;

		if ('' !== $valstr) {
			$valstr = '=[' . $valstr . ']';
		}

		$paramstr = "  \033[1m--" . $name . $valstr . "\033[0m";

		$pl = strlen($paramstr);
		if ($pl + 2 >= $paramw) {
			$paramw = $pl + 3;
		}

		$descw = $this->columns - $paramw;

		$leftcolumn[] = $paramstr;

		if (is_array($values)) {
			foreach ($values as $key => $value) {
				$leftcolumn[] = '    ' . $key . ' - ' . $value;
			}
		}

		if (strlen($description) <= $descw) {
			$rightcolumn[] = $description;
		}
		else {
			$m = explode(' ', $description);

			$descstr = '';

			while (sizeof($m) > 0) {
				$el = array_shift($m);

				if (strlen($descstr) + strlen($el) >= $descw) {
					$rightcolumn[] = $descstr;
					$descstr       = '';
				}
				else {
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
			}
			else {
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
	 * @param  array Array of table's rows
	 * @return void
	 */
	public function drawTable($rows) {
		$pad = [];

		foreach ($rows as $row) {
			foreach ($row as $k => $v) {
				if (substr($k, 0, 1) === '_') {
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
			}
			else {
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
