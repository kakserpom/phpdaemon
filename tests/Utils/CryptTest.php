<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests\Utils;

use PHPDaemon\Core\ComplexJob;
use PHPDaemon\Utils\Crypt;
use Tests\AbstractTestCase;

class CryptTest extends AbstractTestCase
{
    public function testRandomInts()
    {
        $this->prepareAsync();

        $cj = new ComplexJob(function($cj) {
            $this->completeAsync();
        });
        for ($i = 1; $i < 25; ++$i) {
            $cj($i, function($i, $cj) {
                Crypt::randomInts($i, function ($ints) use ($i, $cj) {
                    self::assertCount($i, $ints, '$ints must contain ' . $i . ' element(s)');
                    $cj[$i] = true;
                });
            });
        }
        $cj();
        $this->runAsync(__METHOD__);
    }
}
