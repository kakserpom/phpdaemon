<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests\Utils;

use PHPDaemon\Utils\Crypt;
use Tests\AbstractTestCase;

class CryptTest extends AbstractTestCase
{

    /**
     * @covers Crypt::randomInts
     */
    public function testRandomInts()
    {
        $this->prepareAsync();
        Crypt::randomInts(5, function ($ints) {
            self::assertCount(5, $ints, '$ints must contain 5 elements');
            $this->completeAsync();
        });

        $this->runAsync(__METHOD__);
    }
}
