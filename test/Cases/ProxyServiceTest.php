<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Service\ProxyService;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Testing\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use App\Protocol\ConnectionContext;

/**
 * @internal
 * @coversNothing
 */
class ProxyServiceTest extends TestCase
{
    private ProxyService $proxyService;

    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->createMock(PsrContainerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->proxyService = new ProxyService($container, $logger);
    }

    public function testProxyServiceInstantiation()
    {
        $this->assertInstanceOf(ProxyService::class, $this->proxyService);
    }

    public function testConnectionCanBeCreated()
    {
        // 创建一个模拟的 Swoole 服务器
        $server = $this->createMock(\Swoole\Server::class);
        $server->method('getClientInfo')->willReturn([
            'remote_ip' => '192.168.1.1',
            'remote_port' => 3306
        ]);
        $server->method('send')->willReturn(true);

        // 测试连接创建
        $fd = 100;
        $reactorId = 1;

        // 调用 onConnect 应该不会抛出异常
        try {
            $this->proxyService->onConnect($server, $fd, $reactorId);
            $this->assertTrue(true, '连接创建成功');
        } catch (\Exception $e) {
            $this->fail('连接创建失败: ' . $e->getMessage());
        }
    }

    public function testConnectionClose()
    {
        $server = $this->createMock(\Swoole\Server::class);

        $fd = 100;
        $reactorId = 1;

        // 调用 onClose 应该不会抛出异常
        try {
            $this->proxyService->onClose($server, $fd, $reactorId);
            $this->assertTrue(true, '连接关闭成功');
        } catch (\Exception $e) {
            $this->fail('连接关闭失败: ' . $e->getMessage());
        }
    }

    public function testReceiveWithUnknownConnection()
    {
        $server = $this->createMock(\Swoole\Server::class);

        $fd = 999; // 不存在的连接ID
        $reactorId = 1;
        $data = 'test data';

        // 对于不存在的连接，应该静默处理
        try {
            $this->proxyService->onReceive($server, $fd, $reactorId, $data);
            $this->assertTrue(true, '处理未知连接成功');
        } catch (\Exception $e) {
            $this->fail('处理未知连接失败: ' . $e->getMessage());
        }
    }
}
