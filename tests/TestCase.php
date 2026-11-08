<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return resource
     */
    protected function tempStream(string $contents = '', string $uri = 'php://temp', string $mode = 'r+b')
    {
        $stream = fopen($uri, $mode);
        $this->assertIsResource($stream);

        if ($contents !== '') {
            fwrite($stream, $contents);
            rewind($stream);
        }

        return $stream;
    }
}
