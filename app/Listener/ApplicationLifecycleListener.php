<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\{BootApplication, BeforeWorkerStart};
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use App\Service\ProxyService;

#[Listener]
class ApplicationLifecycleListener implements ListenerInterface
{
    private LoggerInterface $logger;
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(\Hyperf\Logger\LoggerFactory::class)->get('proxy');
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
            BeforeWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof BootApplication) {
            $this->logger->info('应用程序初始化完成', [
                'event' => 'BootApplication',
                'pid' => getmypid(),
            ]);
        }

        if ($event instanceof BeforeWorkerStart) {
            $this->logger->info('准备启动Worker进程', [
                'event' => 'BeforeWorkerStart',
                'workerNum' => $event->serverSetting['worker_num'] ?? 'unknown',
            ]);

            // 测试connection logger
            try {
                $connectionLogger = $this->container->get(\Hyperf\Logger\LoggerFactory::class)->get('connection');
                $connectionLogger->info('BeforeWorkerStart阶段connection logger测试', [
                    'workerNum' => $event->serverSetting['worker_num'] ?? 'unknown',
                    'pid' => getmypid(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('获取connection logger失败', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            // 初始化连接池
            try {
                $proxyService = $this->container->get(ProxyService::class);
                $proxyService->initialize();

                $this->logger->info('连接池初始化完成', [
                    'event' => 'BeforeWorkerStart',
                    'workerNum' => $event->serverSetting['worker_num'] ?? 'unknown',
                    'pid' => getmypid(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('连接池初始化失败', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'event' => 'BeforeWorkerStart',
                    'workerNum' => $event->serverSetting['worker_num'] ?? 'unknown',
                    'pid' => getmypid(),
                ]);
            }
        }
    }
}
