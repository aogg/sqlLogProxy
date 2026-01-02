<?php

namespace App\Helpers;

class PHPSQLParserHelper extends \PHPSQLParser\PHPSQLParser
{
    public mixed $sql = '';
    public function __construct($sql = false, $calcPositions = false, array $options = array())
    {
        parent::__construct($sql, $calcPositions, $options);
        $this->sql = $sql;
    }

    public function isSelect(): bool
    {
        return isset($this->parsed['SELECT']) || isset($this->parsed['SHOW']);
    }

}