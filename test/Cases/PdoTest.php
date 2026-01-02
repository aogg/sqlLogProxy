<?php

namespace HyperfTest\Cases;

use function Hyperf\Support\env;

class PdoTest extends \PHPUnit\Framework\TestCase
{
    public function testPdo()
    {
        $pdo = new \PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname=' . env('DB_DATABASE'),
            env('DB_USERNAME'), env('DB_PASSWORD'));
        $data = $pdo->query('SELECT 1')->fetchAll();
        var_dump($data);
        $this->assertEquals('1', $data[0][0]);
    }

    public function test_databases_Pdo()
    {
        $pdo = new \PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname=' . env('DB_DATABASE'),
            env('DB_USERNAME'), env('DB_PASSWORD'));
        $data = $pdo->query('show databases;')->fetchAll();
        var_dump($data);
        $this->assertContains(env('DB_DATABASE'), array_column($data, 0));
    }

    public function test_tables_use_Pdo()
    {
        $pdo = new \PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname=' . env('DB_DATABASE'),
            env('DB_USERNAME'), env('DB_PASSWORD'));
        $pdo->exec('use mysql');
        $data = $pdo->query('show tables')->fetchAll();
        var_dump($data);
        $this->assertContains('user', array_column($data, 0));
    }

    public function test_tables_Pdo()
    {
        $pdo = new \PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname=' . 'mysql',
            env('DB_USERNAME'), env('DB_PASSWORD'));
        $data = $pdo->query('show tables')->fetchAll();
        var_dump($data);
        $this->assertContains('user', array_column($data, 0));
    }

    public function test_table_user_Pdo()
    {
        $pdo = new \PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';dbname=' . 'mysql',
            env('DB_USERNAME'), env('DB_PASSWORD'));
        $data = $pdo->query('select * from user')->fetchAll();
        var_dump($data);
        $this->assertContains('root', array_column($data, 'User'));
    }
}