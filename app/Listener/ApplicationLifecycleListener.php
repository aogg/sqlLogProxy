<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\{BootApplication, BeforeWorkerStart};
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class ApplicationLifecycleListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
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
        }
    }
}
