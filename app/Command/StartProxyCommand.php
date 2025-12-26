<?php

declare(strict_types=1);

namespace App\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Context\ApplicationContext;
use Hyperf\Server\ServerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;

#[Command]
class StartProxyCommand extends HyperfCommand
{
    protected ContainerInterface $container;
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
        parent::__construct('proxy:start');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('启动MySQL协议代理服务器');
    }

    public function handle()
    {
        $this->logger->info('正在启动MySQL协议代理服务器...');
        $this->logger->info('监听端口: 3306');
        $this->logger->info('按 Ctrl+C 停止服务器');

        // 启动服务器由框架自动处理
        $this->logger->info('服务器已启动');

        // 保持命令运行，防止程序退出
        $this->info('MySQL协议代理服务器已启动，正在运行...');

        $this->logger->debug('等待 Worker 退出协调器...', [
            'current_pid' => getmypid(),
        ]);

        // 等待 Worker 退出协调器，这样可以保持进程运行
        CoordinatorManager::until(Constants::WORKER_EXIT)->yield();

        $this->logger->warning('Worker 退出协调器已触发，命令即将退出');
    }
}
