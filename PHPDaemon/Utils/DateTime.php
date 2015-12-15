<?php
namespace PHPDaemon\Utils;

/**
 * DateTime
 * @package PHPDaemon\Utils
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class DateTime extends \DateTime {
	/**
	 * Support timestamp and available date format
	 * @param string       $time
	 * @param DateTimeZone $timezone
	 * @link http://php.net/manual/en/datetime.construct.php
	 */
	public function __construct($time = 'now', DateTimeZone $timezone = null) {
		if (is_int($time)) {
			parent::__construct('now', $timezone);
			$this->setTimestamp($time);
		} else {
			parent::__construct($time, $timezone);
		}
	}

	/**
	 * Calculates a difference between two dates
	 * @see http://www.php.net/manual/en/datetime.diff.php
	 * @param  integer $datetime1
	 * @param  integer $datetime2
	 * @param  boolean $absolute
	 * @return string Something like this: 1 year. 2 mon. 6 day. 4 hours. 21 min. 10 sec.
	 */
	public static function diffAsText($datetime1, $datetime2, $absolute = false) {
		if (!($datetime1 instanceof \DateTimeInterface)) {
			$datetime1 = new static($datetime1);
		}
		if (!($datetime2 instanceof \DateTimeInterface)) {
			$datetime2 = new static($datetime2);
		}

		$interval = $datetime1->diff($datetime2, $absolute);
		$str      = '';
		$str .= $interval->y ? $interval->y . ' year. ' : '';
		$str .= $interval->m ? $interval->m . ' mon. ' : '';
		$str .= $interval->d ? $interval->d . ' day. ' : '';
		$str .= $interval->h ? $interval->h . ' hour. ' : '';
		$str .= $interval->i ? $interval->i . ' min. ' : '';
		$str .= $interval->s || $str === '' ? $interval->s . ' sec. ' : '';

		return rtrim($str);
	}
}
