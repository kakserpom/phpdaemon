<?php
namespace PHPDaemon\Config\Entry;

/**
 * Config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Generic {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var */
	public $defaultValue;
	/** @var */
	public $value;
	/** @var */
	public $valueType;
	/** @var */
	public $humanValue;
	/** @var */
	public $source;
	/** @var */
	public $revision;
	/** @var bool */
	public $hasDefaultValue = FALSE;
	/** @var bool */
	protected $stackable = false;

	/**
	 * Constructor
	 * @return void
	 */
	public function __construct() {
		if (func_num_args() === 1) {
			$this->setDefaultValue(func_get_arg(0));
			$this->setHumanValue(func_get_arg(0));
		}
	}

	/**
	 * Get human value
	 * @return mixed
	 */
	public function getHumanValue() {
		return $this->humanValue;
	}

	/**
	 * Get value
	 * @return mixed
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * Set value
	 * @param mixed
	 * @return void
	 */
	public function setValue($value) {
		$old              = $this->value;
		$this->value      = $value;
		$this->humanValue = static::PlainToHuman($value);
		$this->onUpdate($old);
	}

	/**
	 * @param bool $b
	 */
	public function setStackable($b = true) {
		$this->stackable = $b;
	}

	/**
	 * @return bool
	 */
	public function isStackable() {
		return $this->stackable;
	}

	/**
	 * Push plain value
	 * @param $value
	 * @return void
	 */
	public function pushValue($value) {
		$old = $this->value;
		if (!$this->stackable) {
			$this->setValue($value);
			return;
		}
		if (!is_array($this->value)) {
			$this->value = [$this->value, $value];
		}
		else {
			$f = false;
			foreach ($this->value as $k => $v) {
				if (!is_int($k)) {
					$f = true;
					break;
				}
			}
			if (!$f) {
				$this->value[] = $value;
			}
			else {
				$this->value = [$this->value, $value];
			}
		}
		$this->onUpdate($old);
	}

	/**
	 * Push human-readable value
	 * @param $value
	 * @return void
	 */
	public function pushHumanValue($value) {
		$this->pushValue(static::HumanToPlain($value));
	}

	/**
	 * Set value type
	 * @param mixed
	 * @return void
	 */
	public function setValueType($type) {
		$this->valueType = $type;
	}

	/**
	 * Reset to default
	 * @return boolean Success
	 */
	public function resetToDefault() {
		if ($this->hasDefaultValue) {
			$this->setHumanValue($this->defaultValue);
			return true;
		}
		return false;
	}

	/**
	 * Set default value
	 * @param mixed
	 * @return void
	 */
	public function setDefaultValue($value) {
		$this->defaultValue    = $value;
		$this->hasDefaultValue = true;
	}

	/**
	 * Set human-readable value
	 * @param mixed
	 * @return void
	 */
	public function setHumanValue($value) {
		$this->humanValue = $value;
		$old              = $this->value;
		$this->value      = static::HumanToPlain($value);
		$this->onUpdate($old);
	}

	/**
	 * Converts human-readable value to plain
	 * @param mixed
	 * @return mixed
	 */
	public static function HumanToPlain($value) {
		return $value;
	}

	/**
	 * Converts plain value to human-readable
	 * @param mixed
	 * @return mixed
	 */
	public static function PlainToHuman($value) {
		return $value;
	}

	/**
	 * Called when
	 * @return void
	 */
	public function onUpdate($old) {
	}

}
