<?php

declare(strict_types=1);

namespace App\Proxy\Auth;

use App\Proxy\Client\ClientType;
use function Hyperf\Config\config;
use Hyperf\Config\Annotation\Value;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 代理账号验证器
 * 验证客户端提供的用户名和密码是否是有效的代理账号
 */
class ProxyAuthenticator
{
    private LoggerInterface $logger;
    private array $proxyAccounts;

    public ClientType $clientType;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('auth');

        // 从配置中加载代理账号
        $config = config('proxy', []);
        $this->proxyAccounts = $config['proxy_accounts'] ?? [];

        $this->logger->info('代理账号验证器初始化', [
            'account_count' => count($this->proxyAccounts),
            'accounts' => array_map(function ($account) {
                return [
                    'username' => $account['username'] ?? '',
                    'database' => $account['database'] ?? '',
                ];
            }, $this->proxyAccounts),
        ]);
    }

    /**
     * 验证客户端提供的认证信息
     *
     * @param string $username 客户端提供的用户名
     * @param string $authResponse 客户端提供的认证响应
     * @param string $authPluginData 握手时发送的认证插件数据
     * @param string $database 客户端请求连接的数据库
     * @return bool 验证是否通过
     */
    public function authenticate(
        string $username,
        string $authResponse,
        string $authPluginData,
        string $database = ''
    ): bool {
        $this->logger->debug('开始验证代理账号', [
            'username' => $username,
            'database' => $database,
            'auth_response_length' => mb_strlen($authResponse),
            'auth_plugin_data_length' => mb_strlen($authPluginData),
        ]);

        // 查找匹配的代理账号
        $account = $this->findAccount($username, $database);

        if (!$account) {
            $this->logger->warning('代理账号不存在或数据库不匹配', [
                'username' => $username,
                'database' => $database,
            ]);
            return false;
        }

        // 验证密码
        $isValid = $this->verifyPassword($authResponse, $account['password'], $authPluginData);

        $this->logger->info('代理账号验证结果', [
            'username' => $username,
            'database' => $database,
            '是否成功' => $isValid,
        ]);

        return $isValid;
    }

    /**
     * 查找匹配的代理账号
     */
    private function findAccount(string $username, string $database = ''): ?array
    {
        foreach ($this->proxyAccounts as $account) {
            if (($account['username'] ?? '') === $username) {
                // 检查数据库限制
                $accountDatabase = $account['database'] ?? '';
                if ($accountDatabase === '' || $accountDatabase === $database) {
                    return $account;
                }
            }
        }

        return null;
    }

    /**
     * 验证密码（使用 MySQL native password 认证算法）
     */
    private function verifyPassword(
        string $clientAuthResponse,
        string $storedPassword,
        string $authPluginData
    ): bool
    {
        // 如果存储的密码为空，检查客户端认证响应是否也为空
        if ($storedPassword === '') {
            return $clientAuthResponse === '';
        }

        // 如果客户端认证响应为空，但存储的密码不为空，验证失败
        if ($clientAuthResponse === '') {
            return false;
        }

        // 计算期望的认证响应
        $expectedResponse = $this->clientType->checkAuth($storedPassword, $authPluginData);
        // $expectedResponse = $this->calculateAuthResponse($storedPassword, $authPluginData);

        // 比较认证响应
        // $isValid = hash_equals(($expectedResponse), $clientAuthResponse);
        $isValid = $expectedResponse === $clientAuthResponse;

        $this->logger->debug('密码验证详情', [
            // 以 hex 形式记录二进制内容，便于对比与调试（生产请谨慎）
            'auth_plugin_data_hex' => bin2hex($authPluginData),
            'client_response_hex' => bin2hex($clientAuthResponse),
            'expected_response_hex' => bin2hex($expectedResponse),
            'client_response_length' => mb_strlen($clientAuthResponse),
            'expected_response_length' => mb_strlen($expectedResponse),
            'responses_match' => $isValid,
        ]);

        return $isValid;
    }


    /**
     * 获取所有代理账号（用于调试和管理）
     */
    public function getAccounts(): array
    {
        return array_map(function ($account) {
            return [
                'username' => $account['username'] ?? '',
                'database' => $account['database'] ?? '',
                'has_password' => !empty($account['password'] ?? ''),
            ];
        }, $this->proxyAccounts);
    }

    /**
     * 检查用户名是否是有效的代理账号
     */
    public function isValidUsername(string $username): bool
    {
        foreach ($this->proxyAccounts as $account) {
            if (($account['username'] ?? '') === $username) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查账号是否可以访问指定的数据库
     */
    public function canAccessDatabase(string $username, string $database): bool
    {
        $account = $this->findAccount($username, $database);
        return $account !== null;
    }

    public function setClientType(ClientType $clientType): self
    {
        $this->clientType = $clientType;
        return $this;
    }
}
