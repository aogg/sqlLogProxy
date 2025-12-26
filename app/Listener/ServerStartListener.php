<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\{OnStart, OnWorkerStart, OnManagerStart};
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class ServerStartListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(\Hyperf\Logger\LoggerFactory::class)->get('proxy');
    }

    public function listen(): array
    {
        return [
            OnStart::class,
            OnManagerStart::class,
            OnWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof OnStart) {
            $this->logger->info('Swoole服务器主进程已启动', [
                'pid' => getmypid(),
                'event' => 'OnStart',
            ]);
        }

        if ($event instanceof OnManagerStart) {
            $this->logger->info('Swoole服务器管理进程已启动', [
                'pid' => getmypid(),
                'event' => 'OnManagerStart',
            ]);
        }

        if ($event instanceof OnWorkerStart) {
            $workerId = $event->workerId;
            $workerType = $event->workerType;
            $this->logger->info("Worker进程已启动", [
                'pid' => getmypid(),
                'worker_id' => $workerId,
                'worker_type' => $workerType,
                'event' => 'OnWorkerStart',
            ]);

            // 测试connection logger
            try {
                $connectionLogger = $this->container->get(\Hyperf\Logger\LoggerFactory::class)->get('connection');
                $connectionLogger->info('Worker进程中connection logger测试', [
                    'worker_id' => $workerId,
                    'worker_type' => $workerType,
                    'pid' => getmypid(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('获取connection logger失败', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            // 测试ProxyService
            try {
                $proxyService = $this->container->get(\App\Service\ProxyService::class);
                $this->logger->info('ProxyService实例获取成功', [
                    'worker_id' => $workerId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('获取ProxyService失败', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    }
}

