<?php

use PHPUnit\Framework\TestCase;
use TorneLIB\Exception\ExceptionHandler;
use TorneLIB\IO\Data\Arrays;
use TorneLIB\MODULE_IO;

require_once(__DIR__ . '/../vendor/autoload.php');

class arrayTest extends TestCase
{
    /**
     * @test
     * If exceptions for unknown methods works.
     */
    public function noRequest()
    {
        static::expectException(ExceptionHandler::class);

        (new MODULE_IO())->getNothing();
    }

    /**
     * @test
     * Test the array-to-stdobject-conversion tool.
     */
    public function arrayToObject()
    {
        $array = [
            'part1' => 'string1',
            'part2' => [
                'subsection' => 'subvalue',
            ],
        ];
        $content = (new Arrays())->arrayObjectToStdClass($array);

        static::assertTrue(
            isset($content->part1) &&
            isset($content->part2) &&
            isset($content->part2->subsection)
        );
    }

    /**
     * @test
     * @throws ExceptionHandler
     */
    public function objectToArray()
    {
        $array = [
            'part1' => 'string1',
            'part2' => [
                'subsection' => 'subvalue',
            ],
        ];
        $content = (new Arrays())->objectsIntoArray(
            (new Arrays())->arrayObjectToStdClass($array)
        );

        static::assertTrue(
            isset($content['part1']) &&
            isset($content['part2']) &&
            isset($content['part2']['subsection'])
        );
    }
}
