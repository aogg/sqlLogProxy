<?php

namespace HyperfTest\Cases;

class DemoTest extends \PHPUnit\Framework\TestCase
{
    public function test_echo()
    {
        echo 1;
        self::assertNotEmpty(true);

    }
}