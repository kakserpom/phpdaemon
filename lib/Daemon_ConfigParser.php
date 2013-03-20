<?php

/**
 * Config parser
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigParser {

	const T_ALL = 1;
	const T_COMMENT = 2;
	const T_VAR = 3;
	const T_STRING = 4;
	const T_BLOCK = 5;
	const T_CVALUE = 5;

	/**
	 * Config file path
	 * @var string
	 */
	protected $file;

	/**
	 * Current line number
	 * @var number
	 */
	protected $line = 1;

	/**
	 * Current column number
	 * @var number
	 */
	protected $col = 1;

	/**
	 * Pointer (current offset)
	 * @var integer
	 */
	protected $p = 0;

	/**
	 * State stack
	 * @var array
	 */
	protected $state = [];

	/**
	 * Target object
	 * @var object
	 */
	protected $target;

	/**
	 * Errorneous?
	 * @var boolean
	 */
	protected $erroneous = false;

	/**
	 * Errorneous?
	 * @return boolean
	 */
	public function isErrorneous() {
		return $this->erroneous;
	}

	/**
	 * Parse config file
	 * @param string File path
	 * @param object Target
	 * @param boolean Included? Default is false
	 * @return Daemon_ConfigParser
	 */
	public static function parse($file, $target, $included = false) {
		return new self($file, $target, $included);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct($file, $target, $included = false) {
		$this->file = $file;
		$this->target = $target;
		$this->revision = ++Daemon_Config::$lastRevision;
		$this->data = file_get_contents($file);
		
		if (substr($this->data, 0, 2) === '#!') {
			if (!is_executable($file)) {
				$this->raiseError('Shebang (#!) detected in the first line, but file hasn\'t +x mode.');
				return;
			}
			$this->data = shell_exec($file);
		}
		
		$this->data = str_replace("\r", '', $this->data);
		$this->len = strlen($this->data);
		$this->state[] = [self::T_ALL, $this->target];
		$this->tokens = [
			self::T_COMMENT => function($c) {
				if ($c === "\n") {
					array_pop($this->state);
				}
			},
			self::T_STRING => function($q) {
				$str = '';
				++$this->p;

				for (; $this->p < $this->len; ++$this->p) {
					$c = $this->getCurrentChar();

					if ($c === $q) {
						++$this->p;
						break;
					}
					elseif ($c === '\\') {
						if ($this->getNextChar() === $q) {
							$str .= $q;
							++$this->p;
						} else {
							$str .= $c;
						}
					} else {
						$str .= $c;
					}
				}

				if ($this->p >= $this->len) {
					$this->raiseError('Unexpected End-Of-File.');
				}
				
				return $str;
			},
			self::T_ALL => function($c) {
				if (ctype_space($c)) { }
				elseif ($c === '#') {
					$this->state[] = [Daemon_ConfigParser::T_COMMENT];
				}
				elseif ($c === '}') {
					if (sizeof($this->state) > 1) {
						$this->purgeScope($this->getCurrentScope());
						array_pop($this->state);
					} else {
						$this->raiseError('Unexpected \'}\'');
					}
				}
				elseif (ctype_alnum($c)) {
					$elements = [''];
					$elTypes = [null];
					$i = 0;
					$tokenType = 0;
					$newLineDetected = null;

					for (;$this->p < $this->len; ++$this->p) {
						$prePoint = [$this->line, $this->col - 1];
						$c = $this->getCurrentChar();

						if (ctype_space($c) || $c === '=' || $c === ',') {
							if ($c === "\n") {
								$newLineDetected = $prePoint;
							}
							if ($elTypes[$i] !== null)	{
								++$i;
								$elTypes[$i] = null;
							}
						}
						elseif (
							($c === '"') 
							|| ($c === '\'')
						) {
							if ($elTypes[$i] != null) {
								$this->raiseError('Unexpected T_STRING.');
							}

							$string = $this->token(Daemon_ConfigParser::T_STRING, $c);
							--$this->p;

							if ($elTypes[$i] === null) {
								$elements[$i] = $string;
								$elTypes[$i] = Daemon_ConfigParser::T_STRING;
							}
						}
						elseif ($c === '}') {
							$this->raiseError('Unexpected \'}\' instead of \';\' or \'{\'');
						}
						elseif ($c === ';') {
							if ($newLineDetected) {
								$this->raiseError('Unexpected new-line instead of \';\'', 'notice', $newLineDetected[0], $newLineDetected[1]);
							}
							$tokenType = Daemon_ConfigParser::T_VAR;
							break;
						}
						elseif ($c === '{') {
							$tokenType = Daemon_ConfigParser::T_BLOCK;
							break;
						} else {
							if ($elTypes[$i] === Daemon_ConfigParser::T_STRING)	 {
								$this->raiseError('Unexpected T_CVALUE.');
							} else {
								if (!isset($elements[$i])) {
									$elements[$i] = '';
								}

								$elements[$i] .= $c;
								$elTypes[$i] = Daemon_ConfigParser::T_CVALUE;
							}
						}
					}
					foreach ($elTypes as $k => $v) {
						if (Daemon_ConfigParser::T_CVALUE === $v) {
							if (ctype_digit($elements[$k])) {
								$elements[$k] = (int) $elements[$k];
							}
							elseif (is_numeric($elements[$k])) {
								$elements[$k] = (float) $elements[$k];
							} else {
								$l = strtolower($elements[$k]);

								if (($l === 'true') || ($l === 'on')) {
									$elements[$k] = true;
								}
								elseif (($l === 'false') || ($l === 'off')) {
									$elements[$k] = false;
								}
								elseif ($l === 'null') {
									$elements[$k] = null;
								}
							}
						}
					}
					if ($tokenType === 0) {
						$this->raiseError('Expected \';\' or \'{\''); 
					}
					elseif ($tokenType === Daemon_ConfigParser::T_VAR) {
						$name = str_replace('-', '', strtolower($elements[0]));
						if (sizeof($elements) > 2) {
							$value = array_slice($elements, 1);
						} else {
							$value = isset($elements[1]) ? $elements[1] : null;
						}
						$scope = $this->getCurrentScope();
						
						if ($name === 'include') {
							if (!is_array($value)) {
								$value = [$value];
							}
							foreach ($value as $path) {
								if (substr($path, 0, 1) !== '/') {
									$path = 'conf/' . $path;
								}
								$files = glob($path);
								if ($files) foreach ($files as $fn) {
									Daemon_ConfigParser::parse($fn, $scope, true);
								}
							}
						} else {
							if ($value === null) {
								$value = true;
							 	$elements[1] = true;
							 	$elTypes[1] = Daemon_ConfigParser::T_CVALUE;
							}
							if (isset($scope->{$name})) {
								if ($scope->{$name}->source !== 'cmdline')	{
									if (($elTypes[1] === Daemon_ConfigParser::T_CVALUE) && is_string($value)) {
										$scope->{$name}->setHumanValue($value);
									} else {
										$scope->{$name}->setValue($value);
									}
									$scope->{$name}->source = 'config';
									$scope->{$name}->revision = $this->revision;
								}
							} elseif (sizeof($this->state) > 1) {
								$scope->{$name} = new Daemon_ConfigEntry();
								$scope->{$name}->source = 'config';
								$scope->{$name}->revision = $this->revision;
								$scope->{$name}->setValue($value);
								$scope->{$name}->setValueType($value);
							}
							else {
								$this->raiseError('Unrecognized parameter \''.$name.'\'');
							}
						}
					}
					elseif ($tokenType === Daemon_ConfigParser::T_BLOCK) {
						$scope = $this->getCurrentScope();
						$sectionName = implode('-', $elements);
						$sectionName = strtr($sectionName, '-. ', ':::');
						if (!isset($scope->{$sectionName})) {
							$scope->{$sectionName} = new Daemon_ConfigSection;
						}
						$scope->{$sectionName}->source = 'config';
						$scope->{$sectionName}->revision = $this->revision;
						$this->state[] = [
							Daemon_ConfigParser::T_ALL,
							$scope->{$sectionName},
						];
					}
				} else {
					$this->raiseError('Unexpected char \''.Debug::exportBytes($c).'\'');
				}
			}
		];

		for (;$this->p < $this->len; ++$this->p) {
			$c = $this->getCurrentChar();
			$e = end($this->state);
			$this->token($e[0], $c);
		}
		if (!$included) {
			$this->purgeScope($this->target);
		}
	}
	
	/**
	 * Removes old config parts after updating.
	 * @return void
	 */
	protected function purgeScope($scope) {
		foreach ($scope as $name => $obj) {
			if ($obj instanceof Daemon_ConfigEntry) {
					if ($obj->source === 'config' && ($obj->revision < $this->revision))	{
						if (!$obj->resetToDefault()) {
							unset($scope->{$name});
						}
					}
			}
			elseif ($obj instanceof Daemon_ConfigSection) {
				
				if ($obj->source === 'config' && ($obj->revision < $this->revision))	{
					if (sizeof($obj) === 0) {
						unset($scope->{$name});
					}
					elseif (isset($obj->enable)) {
						$obj->enable->setValue(FALSE);
					}
				}
			}
		}			
	}
	
	/**
	 * Returns current variable scope
	 * @return object Scope.
	 */
	public function getCurrentScope() {
		$e = end($this->state);

		return $e[1];
	}

	/**
	 * Raises error message.
	 * @param string Message.
	 * @param string Level.
	 * @return void
	 */
	public function raiseError($msg, $level = 'emerg', $line = null, $col = null) {
		if ($level === 'emerg') {
			$this->errorneous = true;
		}
		if ($line === null) {
			$line = $this->line;
		}
		if ($col === null) {
			$col = $this->col -1 ;
		}

		Daemon::log('[conf#' . $level . '][' . $this->file . ' L:' . $line . ' C: ' . $col . ']   '.$msg);
	}

	/**
	 * Executes token server.
	 * @return mixed|void
	 */
	protected function token($token, $c) {
		return call_user_func($this->tokens[$token], $c);
	}
	
	/**
	 * Current character.
	 * @return string Character.
	 */
	protected function getCurrentChar() {
		$c = substr($this->data, $this->p, 1);

		if ($c === "\n") {
			++$this->line;
			$this->col = 1;
		} else {
			++$this->col;
		}

		return $c;
	}

	/**
	 * Returns next character.
	 * @return string Character.
	 */
	protected function getNextChar() {
		return substr($this->data, $this->p + 1, 1);
	}

	/**
	 * Rewinds the pointer back.
	 * @param integer Number of characters to rewind back.
	 * @return void
	 */
	protected function rewind($n) {
		$this->p -= $n;
	}
}
