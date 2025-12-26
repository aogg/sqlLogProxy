<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use Hyperf\Testing\TestCase;
use App\Protocol\ConnectionContext;

/**
 * @internal
 * @coversNothing
 */
class ConnectionContextTest extends TestCase
{
    public function testConnectionContextCreation()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        $this->assertSame('test_client_id', $context->getClientId());
        $this->assertSame('192.168.1.1', $context->getClientIp());
        $this->assertSame(3306, $context->getClientPort());
    }

    public function testMysqlSocketManagement()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        $this->assertNull($context->getMysqlSocket());

        // 模拟设置 socket（仅测试 setter/getter 逻辑）
        $mockSocket = $this->createMock(\Swoole\Coroutine\Socket::class);
        $context->setMysqlSocket($mockSocket);

        $this->assertSame($mockSocket, $context->getMysqlSocket());
    }

    public function testTransactionManagement()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        // 初始状态不在事务中
        $this->assertFalse($context->isInTransaction());

        // 开始事务
        $context->setInTransaction(true);
        $this->assertTrue($context->isInTransaction());

        // 获取事务ID
        $txnId = $context->getTransactionId();
        $this->assertNotEmpty($txnId);
        $this->assertStringStartsWith('txn_', $txnId);

        // 多次获取应返回相同的ID
        $this->assertSame($txnId, $context->getTransactionId());

        // 重置事务
        $context->resetTransaction();
        $this->assertFalse($context->isInTransaction());
        $this->assertEmpty($context->getTransactionId());
    }

    public function testDsnParamsManagement()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        // 初始为空数组
        $this->assertEmpty($context->getDsnParams());

        // 设置参数
        $params = ['group' => 'test_group', 'database' => 'test_db'];
        $context->setDsnParams($params);

        $this->assertSame($params, $context->getDsnParams());
    }

    public function testTargetConfiguration()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        // 初始值为 null
        $this->assertNull($context->getTargetHost());
        $this->assertNull($context->getTargetPort());

        // 设置目标配置
        $context->setTargetHost('mysql.example.com');
        $context->setTargetPort(3307);

        $this->assertSame('mysql.example.com', $context->getTargetHost());
        $this->assertSame(3307, $context->getTargetPort());
    }

    public function testToStringConversion()
    {
        $context = new ConnectionContext('test_client_id', '192.168.1.1', 3306);

        $this->assertSame('192.168.1.1:3306', (string) $context);
    }
}
