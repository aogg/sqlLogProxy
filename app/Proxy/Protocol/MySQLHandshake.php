<?php

declare(strict_types=1);

namespace App\Proxy\Protocol;

use App\Protocol\MySql\Packet;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * MySQL 协议握手处理器（代理作为服务端）
 * 处理客户端连接时的握手和 SSL 协商
 */
class MySQLHandshake
{
    private const PROTOCOL_VERSION = 10;
    private const SERVER_VERSION = '5.7.44-sqlLogProxy';
    private const CHARSET = 33; // utf8mb4_general_ci

    // MySQL 客户端能力标志，支持 SSL 和其他必要功能
    private const CAPABILITIES = 0x00aff7df; // 包含 CLIENT_SSL (2048)

    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('handshake');
    }

    /**
     * 创建服务器握手包
     *
     * @param int $threadId 线程/连接 id
     * @param string|null $authPluginData 如果传入则使用该 auth 插件数据（保证握手与验证使用相同的 salt）
     */
    public function createServerHandshake(int $threadId, ?string $authPluginData = null): Packet
    {
        // 使用外部传入的 authPluginData（如果有），否则生成新的
        if ($authPluginData === null) {
            $authPluginData = $this->generateAuthPluginData(20);
        }
        $authPluginData1 = substr($authPluginData, 0, 8);
        $authPluginDataLen = strlen($authPluginData);
        $authPluginData2 = substr($authPluginData, 8);

        $payload = chr(self::PROTOCOL_VERSION); // Protocol Version
        $payload .= self::SERVER_VERSION . "\x00"; // Server Version
        $payload .= pack('V', $threadId); // Thread ID
        $payload .= $authPluginData1; // Auth Plugin Data Part 1
        $payload .= "\x00"; // Filler
        $payload .= pack('v', self::CAPABILITIES & 0xffff); // Capability Flags Lower
        $payload .= chr(self::CHARSET); // Character Set
        $payload .= pack('v', 0); // Status Flags
        $payload .= pack('v', (self::CAPABILITIES >> 16) & 0xffff); // Capability Flags Upper
        $payload .= chr($authPluginDataLen); // Auth Plugin Data Length
        $payload .= str_repeat("\x00", 10); // Reserved
        $payload .= $authPluginData2; // Auth Plugin Data Part 2
        $payload .= 'mysql_native_password' . "\x00"; // Auth Plugin Name

        $this->logger->debug('创建服务器握手包', [
            'thread_id' => $threadId,
            'server_version' => self::SERVER_VERSION,
            'capabilities' => sprintf('0x%08x', self::CAPABILITIES),
            'charset' => self::CHARSET,
            'auth_plugin_data_length' => $authPluginDataLen,
            // 为调试方便以 hex 形式记录 auth 插件数据（注意生产环境隐私）
            'auth_plugin_data_hex' => bin2hex($authPluginData),
        ]);

        return Packet::create(0, $payload);
    }

    /**
     * 处理客户端握手响应
     */
    public function handleClientHandshakeResponse(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;
        $payloadLength = strlen($payload);

        // Capability Flags (4 bytes)
        if ($offset + 4 > $payloadLength) {
            throw new \RuntimeException('解析客户端握手响应失败：数据包长度不足，无法读取 Capability Flags');
        }
        $capabilities = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // 检查客户端是否请求 SSL
        $clientRequestsSsl = ($capabilities & 0x00000800) !== 0; // CLIENT_SSL

        $this->logger->debug('解析客户端握手响应', [
            'capabilities' => sprintf('0x%08x', $capabilities),
            'client_requests_ssl' => $clientRequestsSsl,
            'sequence_id' => $packet->getSequenceId(),
        ]);

        // 如果客户端请求 SSL，返回特殊响应要求客户端切换到 SSL
        if ($clientRequestsSsl && $packet->getSequenceId() === 1) {
            return [
                'type' => 'ssl_request',
                'capabilities' => $capabilities,
                'ssl_requested' => true,
            ];
        }

        // 解析完整的认证信息
        return $this->parseFullHandshakeResponse($packet);
    }

    /**
     * 解析完整的客户端握手响应（认证信息）
     */
    private function parseFullHandshakeResponse(Packet $packet): array
    {
        $payload = $packet->getPayload();
        $offset = 0;
        $payloadLength = strlen($payload);

        // Capability Flags (4 bytes)
        $capabilities = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Max Packet Size (4 bytes)
        if ($offset + 4 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Max Packet Size');
        }
        $maxPacketSize = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        // Character Set (1 byte)
        if ($offset + 1 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Character Set');
        }
        $charset = ord($payload[$offset]);
        $offset += 1;

        // Reserved (23 bytes)
        if ($offset + 23 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Reserved');
        }
        $offset += 23;

        // Username (NUL terminated string)
        $username = '';
        while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
            $username .= $payload[$offset];
            $offset++;
        }
        $offset++; // skip NUL

        // Auth Response (length encoded integer)
        if ($offset + 1 > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取 Auth Response 长度');
        }
        $authResponseLength = ord($payload[$offset]);
        $offset += 1;

        if ($offset + $authResponseLength > $payloadLength) {
            throw new \RuntimeException('解析握手响应失败：数据包长度不足，无法读取完整的 Auth Response');
        }
        $authResponse = substr($payload, $offset, $authResponseLength);
        $offset += $authResponseLength;

        // Database (NUL terminated string) - only if CLIENT_CONNECT_WITH_DB
        $database = '';
        if ($capabilities & 0x00000008 && $offset < $payloadLength) {
            while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
                $database .= $payload[$offset];
                $offset++;
            }
            $offset++; // skip NUL
        }

        // Auth Plugin Name (NUL terminated string) - only if CLIENT_PLUGIN_AUTH
        $authPluginName = '';
        if ($capabilities & 0x00080000 && $offset < $payloadLength) {
            while (isset($payload[$offset]) && $payload[$offset] !== "\x00") {
                $authPluginName .= $payload[$offset];
                $offset++;
            }
        }

        $this->logger->debug('解析完整客户端认证信息', [
            'username' => $username,
            'database' => $database,
            'auth_plugin_name' => $authPluginName,
            'charset' => $charset,
            'max_packet_size' => $maxPacketSize,
            'auth_response_length' => strlen($authResponse),
        ]);

        return [
            'type' => 'auth_response',
            'capabilities' => $capabilities,
            'max_packet_size' => $maxPacketSize,
            'charset' => $charset,
            'username' => $username,
            'auth_response' => $authResponse,
            'database' => $database,
            'auth_plugin_name' => $authPluginName,
        ];
    }

    /**
     * 生成认证插件数据
     */
    public function generateAuthPluginData(int $length = 20): string
    {
        $data = '';
        for ($i = 0; $i < $length; $i++) {
            $data .= chr(mt_rand(33, 126));
        }
        return $data;
    }

    /**
     * 检查客户端是否支持 SSL
     */
    public function clientSupportsSsl(int $capabilities): bool
    {
        return ($capabilities & 0x00000800) !== 0; // CLIENT_SSL
    }

    /**
     * 创建 SSL 切换响应包
     */
    public function createSslSwitchPacket(): Packet
    {
        // MySQL SSL 切换包是一个特殊的握手响应，只包含能力标志
        $payload = pack('V', self::CAPABILITIES); // Capability Flags
        $payload .= pack('V', 0x00ffffff); // Max Packet Size
        $payload .= chr(self::CHARSET); // Character Set
        $payload .= str_repeat("\x00", 23); // Reserved

        $this->logger->debug('创建 SSL 切换包');

        return Packet::create(1, $payload);
    }
}
