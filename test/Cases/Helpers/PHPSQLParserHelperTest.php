<?php

namespace HyperfTest\Cases\Helpers;

class PHPSQLParserHelperTest extends \PHPUnit\Framework\TestCase
{
    public function test_type()
    {
        $parser = new \App\Helpers\PHPSQLParserHelper('select * from table');
        $this->assertTrue($parser->isSelect());

        $parser = new \App\Helpers\PHPSQLParserHelper('show tables');
        $this->assertTrue($parser->isSelect());
    }
}