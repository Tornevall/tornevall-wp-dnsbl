<?php

namespace TorneLIB;

use Exception;
use PHPUnit\Framework\TestCase;
use TorneLIB\Data\Compress;

require_once(__DIR__ . '/../vendor/autoload.php');

class compressTest extends TestCase
{
    /**
     * @test
     */
    public function testGetGzEncode()
    {
        static::assertNotEmpty(
            (new Compress())->getGzEncode('Hello World')
        );
    }

    /**
     * @test
     * @throws Exception
     */
    public function testGetGzDecode()
    {
        static::assertTrue(
            (new Compress())->getGzDecode((new Compress())->getGzEncode('Hello World')) === 'Hello World'
        );
    }

    /**
     * @test
     */
    public function testGetBzDecode()
    {
        static::assertTrue(
            (new Compress())->getBzDecode(
                (new Compress())->getBzEncode('Hello world')
            ) === 'Hello world'
        );
    }

    /**
     * @test
     * @throws Exception
     */
    public function testGetBzEncode()
    {
        static::assertNotEmpty(
            (new Compress())->getBzEncode('Hello World')
        );
    }

    /**
     * @test
     */
    public function testOldGetGz()
    {
        static::assertNotEmpty(
            (new Compress())->base64_gzencode('Hello World')
        );
    }
}
