<?php
namespace PHPDaemon\Config;

use PHPDaemon\Config\Entry\Generic;
use PHPDaemon\Config\Object;
use PHPDaemon\Config\Section;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\InfiniteRecursion;

/**
 * Config parser
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Parser {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * State: standby
	 */
	const T_ALL     = 1;
	/**
	 * State: comment
	 */
	const T_COMMENT = 2;
	/**
	 * State: variable definition block
	 */
	const T_VAR     = 3;
	/**
	 * Single-quoted string
	 */
	const T_STRING  = 4;
	
	/**
	 * Double-quoted
	 */
	const T_STRING_DOUBLE = 5;
	
	/**
	 * Block
	 */
	const T_BLOCK   = 6;
	
	/**
	 * Value defined by constant (keyword) or number
	 */
	const T_CVALUE  = 7;

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
	 * Erroneous?
	 * @var boolean
	 */
	protected $erroneous = false;

	/**
	 * Callbacks
	 * @var array
	 */
	protected $tokens;

	/**
	 * File length
	 * @var integer
	 */
	protected $length;

	/**
	 * Revision
	 * @var integer
	 */
	protected $revision;

	/**
	 * Contents of config file
	 * @var string
	 */
	protected $data;

	/**
	 * Parse stack
	 * @var array
	 */
	protected static $stack = [];

	/**
	 * Erroneous?
	 * @return boolean
	 */
	public function isErroneous() {
		return $this->erroneous;
	}

	/**
	 * Parse config file
	 * @param string  File path
	 * @param object  Target
	 * @param boolean Included? Default is false
	 * @return \PHPDaemon\Config\Parser
	 */
	public static function parse($file, $target, $included = false) {
		if (in_array($file, static::$stack)) {
			throw new InfiniteRecursion;
			
		}

		static::$stack[] = $file;
		$parser = new static($file, $target, $included);
		array_pop(static::$stack);
		return $parser;
	}

	/**
	 * Constructor
	 * @return void
	 */
	protected function __construct($file, $target, $included = false) {
		$this->file     = $file;
		$this->target   = $target;
		$this->revision = ++Object::$lastRevision;
		$this->data     = file_get_contents($file);

		if (substr($this->data, 0, 2) === '#!') {
			if (!is_executable($file)) {
				$this->raiseError('Shebang (#!) detected in the first line, but file hasn\'t +x mode.');
				return;
			}
			$this->data = shell_exec($file);
		}

		$this->data    = str_replace("\r", '', $this->data);
		$this->length     = strlen($this->data);
		$this->state[] = [static::T_ALL, $this->target];
		$this->tokens  = [
			static::T_COMMENT => function ($c) {
				if ($c === "\n") {
					array_pop($this->state);
				}
			},
			static::T_STRING_DOUBLE  => function ($q) {
				$str = '';
				++$this->p;

				for (; $this->p < $this->length; ++$this->p) {
					$c = $this->getCurrentChar();

					if ($c === $q) {
						++$this->p;
						break;
					}
					elseif ($c === '\\') {
						next:
						$n = $this->getNextChar();
						if ($n === $q) {
							$str .= $q;
							++$this->p;
						}
						elseif (ctype_digit($n)) {
							$def = $n;
							++$this->p;
							for (; $this->p < min($this->length, $this->p + 2); ++$this->p) {
								$n = $this->getNextChar();
								if (!ctype_digit($n)) {
									break;
								}
								$def .= $n;
							}
							$str .= chr((int) $def);
						}
						elseif (($n === 'x') || ($n === 'X')) {
							$def = $n;
							++$this->p;
							for (; $this->p < min($this->length, $this->p + 2); ++$this->p) {
								$n = $this->getNextChar();
								if (!ctype_xdigit($n)) {
									break;
								}
								$def .= $n;
							}
							$str .= chr((int) hexdec($def));
						}
						else {
							$str .= $c;
						}
					}
					else {
						$str .= $c;
					}
				}

				if ($this->p >= $this->length) {
					$this->raiseError('Unexpected End-Of-File.');
				}
				return $str;
			},
			static::T_STRING => function ($q) {
				$str = '';
				++$this->p;

				for (; $this->p < $this->length; ++$this->p) {
					$c = $this->getCurrentChar();

					if ($c === $q) {
						++$this->p;
						break;
					}
					elseif ($c === '\\') {
						if ($this->getNextChar() === $q) {
							$str .= $q;
							++$this->p;
						}
						else {
							$str .= $c;
						}
					}
					else {
						$str .= $c;
					}
				}

				if ($this->p >= $this->length) {
					$this->raiseError('Unexpected End-Of-File.');
				}
				return $str;
			},
			static::T_ALL     => function ($c) {
				if (ctype_space($c)) {
				}
				elseif ($c === '#') {
					$this->state[] = [static::T_COMMENT];
				}
				elseif ($c === '}') {
					if (sizeof($this->state) > 1) {
						$this->purgeScope($this->getCurrentScope());
						array_pop($this->state);
					}
					else {
						$this->raiseError('Unexpected \'}\'');
					}
				}
				elseif (ctype_alnum($c) || $c === '\\') {
					$elements        = [''];
					$elTypes         = [null];
					$i               = 0;
					$tokenType       = 0;
					$newLineDetected = null;

					for (; $this->p < $this->length; ++$this->p) {
						$prePoint = [$this->line, $this->col - 1];
						$c        = $this->getCurrentChar();

						if (ctype_space($c) || $c === '=' || $c === ',') {
							if ($c === "\n") {
								$newLineDetected = $prePoint;
							}
							if ($elTypes[$i] !== null) {
								++$i;
								$elTypes[$i] = null;
							}
						}
						elseif ($c === '\'') {
							if ($elTypes[$i] !== null) {
								$this->raiseError('Unexpected T_STRING.');
							}

							$string = $this->token(static::T_STRING, $c);
							--$this->p;

							if ($elTypes[$i] === null) {
								$elements[$i] = $string;
								$elTypes[$i]  = static::T_STRING;
							}
						}
						elseif ($c === '"') {
							if ($elTypes[$i] !== null) {
								$this->raiseError('Unexpected T_STRING_DOUBLE.');
							}

							$string = $this->token(static::T_STRING_DOUBLE, $c);
							--$this->p;

							if ($elTypes[$i] === null) {
								$elements[$i] = $string;
								$elTypes[$i]  = static::T_STRING_DOUBLE;
							}
						}
						elseif ($c === '}') {
							$this->raiseError('Unexpected \'}\' instead of \';\' or \'{\'');
						}
						elseif ($c === ';') {
							if ($newLineDetected) {
								$this->raiseError('Unexpected new-line instead of \';\'', 'notice', $newLineDetected[0], $newLineDetected[1]);
							}
							$tokenType = static::T_VAR;
							break;
						}
						elseif ($c === '{') {
							$tokenType = static::T_BLOCK;
							break;
						}
						else {
							if ($elTypes[$i] === static::T_STRING) {
								$this->raiseError('Unexpected T_CVALUE.');
							}
							else {
								if (!isset($elements[$i])) {
									$elements[$i] = '';
								}

								$elements[$i] .= $c;
								$elTypes[$i] = static::T_CVALUE;
							}
						}
					}
					foreach ($elTypes as $k => $v) {
						if (static::T_CVALUE === $v) {
							if (ctype_digit($elements[$k])) {
								$elements[$k] = (int)$elements[$k];
							}
							elseif (is_numeric($elements[$k])) {
								$elements[$k] = (float)$elements[$k];
							}
							else {
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
					elseif ($tokenType === static::T_VAR) {
						$name = str_replace('-', '', strtolower($elements[0]));
						if (sizeof($elements) > 2) {
							$value = array_slice($elements, 1);
						}
						else {
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
								if ($files) {
									foreach ($files as $fn) {
										try {
											static::parse($fn, $scope, true);
										} catch (InfiniteRecursion $e) {
											$this->raiseError('Cannot include \'' . $fn . '\' as a part of itself, it may cause an infinite recursion.');
										}
									}
								}
							}
						}
						else {
							if (sizeof($elements) === 1) {
								$value       = true;
								$elements[1] = true;
								$elTypes[1]  = static::T_CVALUE;
							}
							elseif ($value === null) {
								$value       = null;
								$elements[1] = null;
								$elTypes[1]  = static::T_CVALUE;
							}

							if (isset($scope->{$name})) {
								if ($scope->{$name}->source !== 'cmdline') {
									if (($elTypes[1] === static::T_CVALUE) && is_string($value)) {
										$scope->{$name}->pushHumanValue($value);
									}
									else {
										$scope->{$name}->pushValue($value);
									}
									$scope->{$name}->source   = 'config';
									$scope->{$name}->revision = $this->revision;
								}
							}
							elseif ($scope instanceof Section) {
								$scope->{$name}           = new Generic();
								$scope->{$name}->source   = 'config';
								$scope->{$name}->revision = $this->revision;
								$scope->{$name}->pushValue($value);
								$scope->{$name}->setValueType($value);
							}
							else {
								$this->raiseError('Unrecognized parameter \'' . $name . '\'');
							}
						}
					}
					elseif ($tokenType === static::T_BLOCK) {
						$scope       = $this->getCurrentScope();
						$sectionName = implode('-', $elements);
						$sectionName = strtr($sectionName, '-. ', ':::');
						if (!isset($scope->{$sectionName})) {
							$scope->{$sectionName} = new Section;
						}
						$scope->{$sectionName}->source   = 'config';
						$scope->{$sectionName}->revision = $this->revision;
						$this->state[]                   = [
							static::T_ALL,
							$scope->{$sectionName},
						];
					}
				}
				else {
					$this->raiseError('Unexpected char \'' . Debug::exportBytes($c) . '\'');
				}
			}
		];

		for (; $this->p < $this->length; ++$this->p) {
			$c = $this->getCurrentChar();
			$e = end($this->state);
			$this->token($e[0], $c);
		}
		if (!$included) {
			$this->purgeScope($this->target);
		}
		
		if (Daemon::$config->verbosetty->value) {
			Daemon::log('Loaded config file: '. escapeshellarg($file));
		}
	}

	/**
	 * Removes old config parts after updating.
	 * @return void
	 */
	protected function purgeScope($scope) {
		foreach ($scope as $name => $obj) {
			if ($obj instanceof Generic) {
				if ($obj->source === 'config' && ($obj->revision < $this->revision)) {
					if (!$obj->resetToDefault()) {
						unset($scope->{$name});
					}
				}
			}
			elseif ($obj instanceof Section) {

				if ($obj->source === 'config' && ($obj->revision < $this->revision)) {
					if ($obj->count() === 0) {
						unset($scope->{$name});
					}
					elseif (isset($obj->enable)) {
						$obj->enable->setValue(false);
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
	 * @param string $msg
	 * @return void
	 */
	public function raiseError($msg, $level = 'emerg', $line = null, $col = null) {
		if ($level === 'emerg') {
			$this->erroneous = true;
		}
		if ($line === null) {
			$line = $this->line;
		}
		if ($col === null) {
			$col = $this->col - 1;
		}

		Daemon::log('[conf#' . $level . '][' . $this->file . ' L:' . $line . ' C: ' . $col . ']   ' . $msg);
	}

	/**
	 * Executes token server.
	 * @param string $c
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
		}
		else {
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
