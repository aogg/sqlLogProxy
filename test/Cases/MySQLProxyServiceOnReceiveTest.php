<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Proxy\Service\MySQLProxyService;
use App\Protocol\ConnectionContext;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Server;

/**
 * @internal
 * @coversNothing
 */
class MySQLProxyServiceOnReceiveTest extends \PHPUnit\Framework\TestCase
{
    private MySQLProxyService $proxyService;
    private $loggerMock;

    private $handshakeMock;
    // private $authenticatorMock;
    private $executorMock;
    private $clientDetectorMock;
    private $protocolAdapterMock;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建各种 mock
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        // $this->handshakeMock = $this->createMock(
        //     \App\Proxy\Protocol\MySQLHandshake::class
        // );
        $this->handshakeMock =
        // $this->authenticatorMock = $this->createMock(
        //     \App\Proxy\Auth\ProxyAuthenticator::class
        // );
        $this->executorMock = $this->createMock(
            \App\Proxy\Executor\BackendExecutor::class
        );
        $this->clientDetectorMock = $this->createMock(
            \App\Proxy\Client\ClientDetector::class
        );
        $this->protocolAdapterMock = $this->createMock(
            \App\Proxy\Client\ProtocolAdapter::class
        );

        // 创建 logger factory mock
        $loggerFactoryMock = $this->createMock(
            \Hyperf\Logger\LoggerFactory::class
        );
        $loggerFactoryMock->method('get')->willReturn($this->loggerMock);

        // 创建服务
        $this->proxyService = new MySQLProxyService(
            $loggerFactoryMock,
            // $this->handshakeMock,
            // $this->authenticatorMock,
            $this->executorMock,
            $this->clientDetectorMock,
            $this->protocolAdapterMock
        );

        // 设置默认的 logger 期望
        $this->loggerMock->method('info');
        $this->loggerMock->method('debug');
        $this->loggerMock->method('error');
        $this->loggerMock->method('warning');

    }

    /**
     * 测试：收到未知客户端的数据
     */
    public function testReceiveWithUnknownConnection()
    {
        $server = $this->createMock(Server::class);
        $fd = 999;
        $reactorId = 1;
        $data = 'test data';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                '收到未知客户端的数据',
                $this->callback(function ($context) use ($fd) {
                    return $context['client_id'] === (string)$fd;
                })
            );

        $server->expects($this->once())
            ->method('close')
            ->with($fd);

        $this->proxyService->onReceive($server, $fd, $reactorId, $data);
    }

    /**
     * 测试：收到空数据
     */
    public function testReceiveWithEmptyData()
    {
        $server = $this->createMock(Server::class);
        $fd = 100;
        $reactorId = 1;
        $data = '';

        $this->addConnectionContext($fd, $server);

        $this->proxyService->onReceive($server, $fd, $reactorId, $data);

        $this->assertTrue(true);
    }

    /**
     * 测试：收到实际的认证响应数据（基于日志）
     */
    public function testReceiveWithActualAuthResponseData()
    {
        $server = $this->createMock(Server::class);
        $fd = 1;
        $reactorId = 0;

        $authResponseBase64 = 'VgAAAQ+iLgD///8ALQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAcm9vdAAUAa0Kzy/PwAiHK1dKGzuOtV88Ul9teXNxbABteXNxbF9uYXRpdmVfcGFzc3dvcmQA';
        $authResponseData = base64_decode($authResponseBase64);

        $this->assertNotFalse($authResponseData);
        $this->assertGreaterThan(0, strlen($authResponseData));

        $context = $this->addConnectionContext($fd, $server);
        $context->setUsername('root');
        $context->setDatabase('mysql');



        $getAuthPluginData = 'Kz80Km8hImcyLl4uRH5+ZU1cdFhG';
        $context->setAuthPluginData(base64_decode($getAuthPluginData));


        $server->expects($this->once())
            ->method('send')
            ->with($fd, $this->anything());


        // 设置 connection logger 期望
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                '处理数据包异常',
                $this->callback(function ($context) use ($fd) {
                    return !isset($context['error']);
                })
            );

        $this->proxyService->onReceive($server, $fd, $reactorId, $authResponseData);

        $this->assertTrue(true);
    }

    /**
     * 辅助方法：添加连接上下文
     */
    private function addConnectionContext(int $fd, Server $server)
    {
        $reflection = new \ReflectionClass($this->proxyService);
        $connectionsProperty = $reflection->getProperty('connections');
        $connectionsProperty->setAccessible(true);

        $context = new ConnectionContext($fd, '127.0.0.1', 3309);
        $connectionsProperty->setValue($this->proxyService, [(string)$fd => $context]);

        return $context;
    }
}
