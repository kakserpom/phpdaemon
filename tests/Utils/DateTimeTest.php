<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests\Utils;

use PHPDaemon\Utils\DateTime;

class DateTimeTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructWithTimestampAndDefaultTimeZone()
    {
        $time = time() - 60 * 60 * 5;

        $datetime = new DateTime($time);
        self::assertEquals($time, $datetime->getTimestamp());
        self::assertEquals(new \DateTimeZone(date_default_timezone_get()), $datetime->getTimezone());
    }

    public function testConstructWithDateAndDefaultTimeZone()
    {
        $datetime = new DateTime();
        self::assertEquals(new \DateTimeZone(date_default_timezone_get()), $datetime->getTimezone());
    }

    public function testConstructWithDateAndTimeZone()
    {
        $timezone = new \DateTimeZone('Europe/London');
        $date = '2016-05-14T02:30:59'; // Without timezone to not redeclare it

        $datetime = new DateTime($date, $timezone);

        self::assertEquals(
            $date,
            $datetime->format('Y-m-d\TH:i:s') // Not a const because without timezone format
        );
        self::assertEquals($timezone, $datetime->getTimezone());
    }

    public function testConstructWithTimeStampAndTimeZone()
    {
        $time = time() - 60 * 60 * 5;

        $timezone = new \DateTimeZone('Europe/London');
        $datetime = new DateTime($time, $timezone);

        self::assertEquals($time, $datetime->getTimestamp());
        self::assertEquals($timezone, $datetime->getTimezone());
    }

    public function getDataForDiffAsText()
    {
        return [
            [
                '2015-04-15T12:15:01',
                '2016-05-14T00:00:00',
                '1 year. 28 day. 11 hour. 44 min. 59 sec.'
            ],
            [
                '2016-05-13T00:00:00',
                '2016-05-14T00:00:00',
                '1 day.'
            ],
            [
                '2016-05-14T01:00:00',
                '2016-05-14T02:00:00',
                '1 hour.'
            ],
            [
                '2016-05-14T00:00:00',
                '2016-05-14T00:01:15',
                '1 min. 15 sec.'
            ],
            [
                '2016-05-14T00:00:00',
                '2016-05-14T00:00:30',
                '30 sec.'
            ],
            [
                '2016-05-14T00:00:00',
                '2016-05-14T00:00:15',
                '15 sec.'
            ],
            [
                '2016-05-14T00:00:00',
                '2016-05-14T00:00:00',
                '0 sec.'
            ],
        ];
    }

    /**
     * @dataProvider getDataForDiffAsText
     *
     * @param $dateA
     * @param $dateB
     * @param $expected
     */
    public function testDiffAsText($dateA, $dateB, $expected)
    {
        self::assertEquals(
            $expected,
            DateTime::diffAsText(
                new DateTime($dateA),
                new DateTime($dateB)
            )
        );
    }
}
