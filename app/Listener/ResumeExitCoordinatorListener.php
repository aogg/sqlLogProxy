<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Listener;

use Hyperf\Command\Event\AfterExecute;
use Hyperf\Command\Event\BeforeExecute;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class ResumeExitCoordinatorListener implements ListenerInterface
{
    private LoggerInterface $logger;
    private bool $isProxyStart = false;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function listen(): array
    {
        return [
            BeforeExecute::class,
            AfterExecute::class,
        ];
    }

    public function process(object $event): void
    {
        if ($event instanceof BeforeExecute) {
            $command = $event->getCommand();
            if ($command !== null && $command->getName() === 'proxy:start') {
                $this->isProxyStart = true;
                $this->logger->debug('检测到 proxy:start 命令，将保持服务器运行');
            }
            return;
        }

        if ($event instanceof AfterExecute) {
            $command = $event->getCommand();
            $commandName = $command !== null ? $command->getName() : 'unknown';

            if ($this->isProxyStart && $commandName === 'proxy:start') {
                // proxy:start 命令不应该退出，保持服务器运行
                $this->logger->info('MySQL代理服务器已启动，正在运行...');
                $this->logger->debug('跳过 Worker 退出协调器的 resume 操作');
                return;
            }

            $this->logger->debug("命令 {$commandName} 执行完成，恢复 Worker 退出协调器");
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
        }
    }
}
