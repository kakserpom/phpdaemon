<?php
namespace PHPDaemon\Utils;

class DateTime extends \DateTime
{

    /**
     * create new object from timestamp or available date format
     * @see http://www.php.net/manual/en/datetime.formats.date.php
     *
     * @param $datetime
     *
     * @return static
     */
    public static function createObject($datetime)
    {
        if (is_int($datetime)) {
            $newObject = new static;
            $newObject->setTimestamp($datetime);
        } else {
            $newObject = new static($datetime);
        }

        return $newObject;
    }

    /**
     * Calculates a difference between two dates.
     * @see http://www.php.net/manual/en/datetime.diff.php
     *
     * @param $datetime1
     * @param $datetime2
     * @param $absolute
     *
     * @return string Something like this: 1 year. 2 mon. 6 day. 4 hours. 21 min. 10 sec.
     */
    public static function diffAsText($datetime1, $datetime2, $absolute = false)
    {
        if (!($datetime1 instanceof \DateTimeInterface)) {
            $datetime1 = static::createObject($datetime1);
        }
        if (!($datetime2 instanceof \DateTimeInterface)) {
            $datetime2 = static::createObject($datetime2);
        }

        $interval = $datetime1->diff($datetime2, $absolute);
        $str      = '';
        $str .= $interval->y ? $interval->y . ' year. ' : '';
        $str .= $interval->m ? $interval->m . ' mon. ' : '';
        $str .= $interval->d ? $interval->d . ' day. ' : '';
        $str .= $interval->h ? $interval->h . ' hour. ' : '';
        $str .= $interval->i ? $interval->i . ' min. ' : '';
        $str .= $interval->s || $str == '' ? $interval->s . ' sec. ' : '';

        return rtrim($str);
    }

}