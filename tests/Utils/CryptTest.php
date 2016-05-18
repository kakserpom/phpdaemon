<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests\Utils;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Timer;
use PHPDaemon\Thread\Master;
use PHPDaemon\Utils\Crypt;

class CryptTest extends \PHPUnit_Framework_TestCase
{
    protected function tearDown()
    {
        while (!Daemon::$process->eventBase->loop()) {
            /**
             * Weight timers
             */
        }
    }

    public function testConstructWithTimestampAndDefaultTimeZone()
    {
        Daemon::$process = new Master();
        Daemon::$process->eventBase = new \EventBase();

        $callbacksStack = new \SplStack();

        foreach (range(1, 25) as $neededIntegers) {
            $cb = function ($resultIntegers) use ($neededIntegers, $callbacksStack) {
                $callbacksStack->shift();

                self::assertCount($neededIntegers, $resultIntegers);

                foreach ($resultIntegers as $integer) {
                    self::assertInternalType('integer', $integer);
                }
            };

            $callbacksStack->push($neededIntegers);
            Crypt::randomInts($neededIntegers, $cb);
        }

        Timer::add(function ($event) use ($callbacksStack) {
            self::assertSame(0, $callbacksStack->count(), 'Some callbacks didnt finished in ' . __METHOD__);
        }, 2e6);
    }
}
