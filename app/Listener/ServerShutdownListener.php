<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Command\Event\AfterExecute;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\{OnShutdown, OnWorkerStop, OnWorkerExit};
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class ServerShutdownListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(\Hyperf\Logger\LoggerFactory::class)->get('proxy');
    }

    public function listen(): array
    {
        return [
            OnShutdown::class,
            OnWorkerStop::class,
            OnWorkerExit::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof OnShutdown) {
            $signal = pcntl_signal_get_handler(SIGTERM) ?? 'unknown';
            $this->logger->info('Swoole服务器主进程已停止', [
                'pid' => getmypid(),
                'event' => 'OnShutdown',
                'signal' => $signal,
                'exit_code' => $event->exitCode ?? 'unknown',
            ]);
        }

        if ($event instanceof OnWorkerStop) {
            $workerId = $event->workerId;
            $this->logger->info("Worker进程已停止", [
                'pid' => getmypid(),
                'worker_id' => $workerId,
                'event' => 'OnWorkerStop',
            ]);
        }

        if ($event instanceof OnWorkerExit) {
            $workerId = $event->workerId;
            $this->logger->info("Worker进程正在退出", [
                'pid' => getmypid(),
                'worker_id' => $workerId,
                'event' => 'OnWorkerExit',
            ]);
        }
    }
}

