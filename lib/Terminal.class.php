<?php

class Terminal {
	public $enable_color = FALSE;

	/**
	 * @method readln
	 * @description Reads a line from STDIN.
	 * @return string Line.
	 */
	public function readln() {
		return fgets(STDIN);
	}

	/**
	 * @method clearScreen
	 * @description Sends CLR.
	 * @return void
	 */
	public function clearScreen() {
		echo "\x0c";
	}

	/**
	 * @method setStyle
	 * @param string Style.
	 * @description Sets text-style.
	 * @return void
	 */
	public function setStyle($c) {
		if ($this->enable_color) {
			echo "\033[".$c.'m';
		}
	}

	/**
	 * @method resetStyle
	 * @description Sets default style.
	 * @return void
	 */
	public function resetStyle() {
		if ($this->enable_color) {
			echo "\033[0m";
		}
	}

	/**
	 * @method drawTable
	 * @param array Array of table's rows.
	 * @description Draw textual table.
	 * @return void
	 */
	public function drawTable($rows) {
		$pad = array();

		foreach ($rows as $row) {
			foreach ($row as $k => $v) {
				if (substr($k, 0, 1) == '_') {
					continue;
				} 

				if (
					!isset($pad[$k]) 
					|| (strlen($v) > $pad[$k])
				) {
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
					if (substr($k, 0, 1) == '_') {
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
