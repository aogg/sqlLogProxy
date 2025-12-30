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

use App\Proxy\Service\MySQLProxyService;
use App\Proxy\Protocol\MySQLHandshake;
use App\Proxy\Auth\ProxyAuthenticator;
use App\Proxy\Executor\BackendExecutor;
use App\Proxy\Client\ClientDetector;
use App\Proxy\Client\ProtocolAdapter;

return [
    // MySQL 代理服务组件
    MySQLHandshake::class => MySQLHandshake::class,
    ProxyAuthenticator::class => ProxyAuthenticator::class,
    BackendExecutor::class => BackendExecutor::class,
    MySQLProxyService::class => MySQLProxyService::class,

    // 客户端识别和适配组件
    ClientDetector::class => ClientDetector::class,
    ProtocolAdapter::class => ProtocolAdapter::class,
];
